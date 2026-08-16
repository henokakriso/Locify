/**
 * LOCIFY — Local Security Monitor
 * Project ARWE
 *
 * Runs on the Local Office Server. Detects file-integrity violations on the
 * LOCIFY tree (tamper detection for PHP sources and audit data), reports to
 * stderr/syslog, and can signal the PHP application through a small status
 * file. Runs with minimum privileges; never executes untrusted input.
 *
 * Build:  gcc -O2 -Wall -Wextra -o security_monitor security_monitor.c
 * Usage:  ./security_monitor /srv/locify /var/lib/locify/security/monitor.db 300
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <stdbool.h>
#include <signal.h>
#include <time.h>
#include <dirent.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <openssl/sha.h>

#define MAX_PATH 4096
#define MAX_LINE 8192
#define MAX_MANIFEST 1048576 /* 1 MB manifest budget */
#define DEFAULT_INTERVAL 300

static volatile sig_atomic_t g_running = 1;

static void handle_signal(int sig)
{
    (void)sig;
    g_running = 0;
}

/* SHA-256 of a file into a hex string (static buffer). */
static const char *file_sha256(const char *path)
{
    static char hex[65];
    unsigned char digest[SHA256_DIGEST_LENGTH];
    FILE *fp = fopen(path, "rb");

    if (!fp)
        return NULL;

    SHA256_CTX ctx;
    SHA256_Init(&ctx);

    unsigned char buf[65536];
    size_t n;
    while ((n = fread(buf, 1, sizeof(buf), fp)) > 0)
        SHA256_Update(&ctx, buf, n);
    fclose(fp);

    SHA256_Final(digest, &ctx);
    for (int i = 0; i < SHA256_DIGEST_LENGTH; i++)
        sprintf(hex + i * 2, "%02x", digest[i]);
    hex[64] = '\0';
    return hex;
}

/* Walk a directory tree and write "path<TAB>sha256" manifest lines. */
static void build_manifest(const char *root, FILE *out)
{
    DIR *dir = opendir(root);
    if (!dir)
        return;

    struct dirent *entry;
    while ((entry = readdir(dir)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
            continue;

        char path[MAX_PATH];
        if (snprintf(path, sizeof(path), "%s/%s", root, entry->d_name) >= (int)sizeof(path))
            continue;

        struct stat st;
        if (stat(path, &st) != 0)
            continue;

        if (S_ISDIR(st.st_mode)) {
            build_manifest(path, out);
        } else if (S_ISREG(st.st_mode)) {
            const char *hash = file_sha256(path);
            if (hash)
                fprintf(out, "%s\t%s\n", path, hash);
        }
    }
    closedir(dir);
}

/* Compare current state against the manifest file; report deviations. */
static int check_against_manifest(const char *root, const char *manifest_path)
{
    char tmp_path[MAX_PATH];
    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp.%d", manifest_path, (int)getpid());

    FILE *tmp = fopen(tmp_path, "w");
    if (!tmp)
        return -1;
    build_manifest(root, tmp);
    fclose(tmp);

    FILE *a = fopen(manifest_path, "r");
    if (!a) {
        /* First run: adopt the current state as baseline. */
        rename(tmp_path, manifest_path);
        fprintf(stderr, "[security] baseline established\n");
        return 0;
    }

    FILE *b = fopen(tmp_path, "r");
    if (!b) {
        fclose(a);
        return -1;
    }

    char la[MAX_LINE], lb[MAX_LINE];
    int violations = 0;

    /* Read both manifests and compare. */
    while (fgets(la, sizeof(la), a)) {
        /* Find the same path in the current manifest. */
        char path_a[MAX_PATH];
        sscanf(la, "%4095s", path_a);

        rewind(b);
        int found = 0;
        while (fgets(lb, sizeof(lb), b)) {
            char path_b[MAX_PATH];
            sscanf(lb, "%4095s", path_b);
            if (strcmp(path_a, path_b) == 0) {
                found = 1;
                if (strcmp(la, lb) != 0) {
                    fprintf(stderr, "[security] TAMPER DETECTED: %s\n", path_a);
                    violations++;
                }
                break;
            }
        }
        if (!found) {
            fprintf(stderr, "[security] FILE REMOVED: %s\n", path_a);
            violations++;
        }
    }

    /* Detect newly added files. */
    while (fgets(lb, sizeof(lb), b)) {
        char path_b[MAX_PATH];
        sscanf(lb, "%4095s", path_b);
        rewind(a);
        int found = 0;
        while (fgets(la, sizeof(la), a)) {
            char path_a[MAX_PATH];
            sscanf(la, "%4095s", path_a);
            if (strcmp(path_a, path_b) == 0) {
                found = 1;
                break;
            }
        }
        if (!found)
            fprintf(stderr, "[security] NEW FILE (unexpected): %s\n", path_b);
    }

    fclose(a);
    fclose(b);
    remove(tmp_path);

    return violations;
}

int main(int argc, char **argv)
{
    if (argc < 3 || argc > 4) {
        fprintf(stderr, "usage: %s <locify-root> <manifest-file> [interval-seconds]\n", argv[0]);
        return 1;
    }

    const char *root = argv[1];
    const char *manifest = argv[2];
    long interval = argc == 4 ? strtol(argv[3], NULL, 10) : DEFAULT_INTERVAL;
    if (interval <= 0)
        interval = DEFAULT_INTERVAL;

    signal(SIGINT, handle_signal);
    signal(SIGTERM, handle_signal);

    fprintf(stderr, "[security] monitor started (root=%s interval=%lds)\n", root, interval);

    while (g_running) {
        int violations = check_against_manifest(root, manifest);
        if (violations > 0)
            fprintf(stderr, "[security] %d integrity violations detected\n", violations);
        for (int i = 0; i < interval && g_running; i++)
            sleep(1);
    }

    fprintf(stderr, "[security] monitor stopped\n");
    return 0;
}
