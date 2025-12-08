SHELL := /bin/bash

all: build

help:
	@echo "Available targets:"
	@awk '/^[a-zA-Z0-9_-]+:/ {print "  " $$1}' $(MAKEFILE_LIST)

.PHONY: build push update push-terminal push-wp

setup-user:
	bash ./scripts/setup-user-permissions.sh

build:
	bash ./scripts/build-terminal.sh

push:
	source ../.env && \
	export TERMINAL_IMAGE_NAME="$${TERMINAL_IMAGE_NAME}" && \
	bash ./scripts/build-push-image.sh "$${TERMINAL_IMAGE_NAME}" ./docker/terminal "$$@"

update:
	bash ./scripts/services-pull-images.sh "$$@"
