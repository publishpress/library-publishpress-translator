SHELL := /bin/bash

all: update

help:
	@echo "Available targets:"
	@awk '/^[a-zA-Z0-9_-]+:/ {print "  " $$1}' $(MAKEFILE_LIST)

.PHONY: update push-wp

setup-user:
	bash ./scripts/setup-user-permissions.sh

update:
	bash ./scripts/services-pull-images.sh "$$@"
