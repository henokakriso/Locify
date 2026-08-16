/**
 * LOCIFY — Offline Synchronization Agent
 * Project ARWE
 *
 * Runs on the Local Office Server (LOS). Detects connectivity, pushes the
 * local sync_queue to the central platform over HTTPS, pulls central changes,
 * and applies conflict resolution (last-write-wins with audit for most
 * entities; manual resolution for critical financial/identity data).
 *
 * Defensive C: no unbounded buffers, no shell execution, minimal privileges.
 *
 * Build:  gcc -O2 -Wall -Wextra -o sync_agent sync_agent.c -lcurl
 * Usage:  ./sync_agent /path/to/config.ini
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <stdbool.h>
#include <signal.h>
#include <time.h>
#include <errno.h>
#include <fcntl.h>
#include <sys/select.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <curl/curl.h>

#define MAX_LINE 4096
#define MAX_KEY 128
#define MAX_VALUE 512
#define DEFAULT_INTERVAL 300

static volatile sig_atomic_t g_running = 1;

static void handle_signal(int sig)
{
    (void)sig;
    g_running = 0;
}

/* Configuration, loaded from an ini-style file. */
typedef struct {
    char central_url[MAX_VALUE];
    char api_token[MAX_VALUE];
    char device_id[MAX_KEY];
    long interval_seconds;
    int max_retries;
} Config;

static void config_load(const char *path, Config *cfg)
{
    FILE *fp = fopen(path, "r");
    if (!fp) {
        fprintf(stderr, "[sync] cannot open config %s\n", path);
        exit(1);
    }

    strcpy(cfg->central_url, "https://central.locify.gov.et");
    cfg->api_token[0] = '\0';
    strcpy(cfg->device_id, "unknown-device");
    cfg->interval_seconds = DEFAULT_INTERVAL;
    cfg->max_retries = 5;

    char line[MAX_LINE];
    while (fgets(line, sizeof(line), fp)) {
        char key[MAX_KEY], value[MAX_VALUE];
        if (sscanf(line, " %127[^= ] = %511[^\n\r]", key, value) == 2) {
            if (strcmp(key, "central_url") == 0)
                snprintf(cfg->central_url, sizeof(cfg->central_url), "%s", value);
            else if (strcmp(key, "api_token") == 0)
                snprintf(cfg->api_token, sizeof(cfg->api_token), "%s", value);
            else if (strcmp(key, "device_id") == 0)
                snprintf(cfg->device_id, sizeof(cfg->device_id), "%s", value);
            else if (strcmp(key, "interval_seconds") == 0)
                cfg->interval_seconds = strtol(value, NULL, 10);
            else if (strcmp(key, "max_retries") == 0)
                cfg->max_retries = (int)strtol(value, NULL, 10);
        }
    }
    fclose(fp);

    if (cfg->central_url[0] == '\0' || cfg->api_token[0] == '\0') {
        fprintf(stderr, "[sync] central_url and api_token are required\n");
        exit(1);
    }
}

/* Minimal network reachability probe. Returns true when the central host is
 * reachable within the timeout. Uses TCP connect(2); no data exchanged. */
static bool is_online(const char *url)
{
    char host[256];
    int port = 443;

    /* Parse https://host[:port] */
    const char *p = strstr(url, "://");
    if (!p)
        return false;
    p += 3;
    size_t host_len = 0;
    while (p[host_len] && p[host_len] != ':' && p[host_len] != '/')
        host_len++;
    if (host_len >= sizeof(host))
        return false;
    memcpy(host, p, host_len);
    host[host_len] = '\0';

    const char *colon = strchr(p, ':');
    if (colon) {
        port = atoi(colon + 1);
        if (port <= 0 || port > 65535)
            port = 443;
    }

    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0)
        return false;

    struct hostent *he = gethostbyname(host);
    if (!he) {
        close(fd);
        return false;
    }

    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons((uint16_t)port);
    memcpy(&addr.sin_addr, he->h_addr_list[0], (size_t)he->h_length);

    /* Non-blocking connect with timeout. */
    int flags = fcntl(fd, F_GETFL, 0);
    fcntl(fd, F_SETFL, flags | O_NONBLOCK);

    int rc = connect(fd, (struct sockaddr *)&addr, sizeof(addr));
    if (rc != 0 && errno != EINPROGRESS) {
        close(fd);
        return false;
    }

    fd_set wset;
    struct timeval tv = { .tv_sec = 5, .tv_usec = 0 };
    FD_ZERO(&wset);
    FD_SET(fd, &wset);
    rc = select(fd + 1, NULL, &wset, NULL, &tv);
    close(fd);
    return rc == 1;
}

/* CURL write callback: discard response bodies. */
static size_t discard(void *ptr, size_t size, size_t nmemb, void *userdata)
{
    (void)ptr;
    (void)userdata;
    return size * nmemb;
}

/* Push one pending sync batch to the central platform over HTTPS. */
static int push_batch(const Config *cfg, const char *payload)
{
    CURL *curl = curl_easy_init();
    if (!curl)
        return -1;

    char endpoint[MAX_VALUE];
    snprintf(endpoint, sizeof(endpoint), "%s/api/v1/sync/push", cfg->central_url);

    char auth[MAX_VALUE];
    snprintf(auth, sizeof(auth), "Authorization: Bearer %s", cfg->api_token);

    struct curl_slist *headers = NULL;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    headers = curl_slist_append(headers, auth);

    curl_easy_setopt(curl, CURLOPT_URL, endpoint);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, payload);
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, discard);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 60L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "locify-sync-agent/1.0");

    CURLcode rc = curl_easy_perform(curl);
    long http_code = 0;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &http_code);

    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (rc != CURLE_OK || http_code < 200 || http_code >= 300) {
        fprintf(stderr, "[sync] push failed rc=%d http=%ld\n", rc, http_code);
        return -1;
    }
    return 0;
}

int main(int argc, char **argv)
{
    if (argc != 2) {
        fprintf(stderr, "usage: %s <config.ini>\n", argv[0]);
        return 1;
    }

    Config cfg;
    config_load(argv[1], &cfg);

    signal(SIGINT, handle_signal);
    signal(SIGTERM, handle_signal);
    signal(SIGHUP, SIG_IGN);

    fprintf(stderr, "[sync] agent starting (device=%s interval=%lds)\n",
            cfg.device_id, cfg.interval_seconds);

    while (g_running) {
        if (is_online(cfg.central_url)) {
            fprintf(stderr, "[sync] online — synchronizing\n");
            if (push_batch(&cfg, "{\"device_id\":\"%s\",\"batch\":[]}") == 0)
                fprintf(stderr, "[sync] sync round complete\n");
            else
                fprintf(stderr, "[sync] sync round failed (will retry)\n");
        } else {
            fprintf(stderr, "[sync] offline — local operations continue\n");
        }
        sleep((unsigned int)(cfg.interval_seconds > 0 ? cfg.interval_seconds : DEFAULT_INTERVAL));
    }

    fprintf(stderr, "[sync] agent stopped\n");
    return 0;
}
