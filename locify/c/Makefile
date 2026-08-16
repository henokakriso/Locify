# LOCIFY C services — build file
# Defensive builds: -fstack-protector-strong -D_FORTIFY_SOURCE=2 -O2 -Wall -Wextra

CC      = gcc
CFLAGS  = -O2 -Wall -Wextra -Wformat=2 -Wshadow -fstack-protector-strong -D_FORTIFY_SOURCE=2
LIBS    = -lcurl -lcrypto

BIN     = ../storage/local-sync

all: $(BIN)/sync_agent $(BIN)/security_monitor

$(BIN)/sync_agent: sync/sync_agent.c
	@mkdir -p $(BIN)
	$(CC) $(CFLAGS) -o $@ $< $(LIBS)

$(BIN)/security_monitor: security/security_monitor.c
	@mkdir -p $(BIN)
	$(CC) $(CFLAGS) -o $@ $< -lcrypto

clean:
	rm -f $(BIN)/sync_agent $(BIN)/security_monitor

.PHONY: all clean
