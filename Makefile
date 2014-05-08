# This Makefile downloads and installs dl-api dependencies and the dl-api commandline. 
# It depends on GNU Make. 
# For dl-api installation we need php-cli, php-json and npm. 
# It has been tested on Linux (Ubuntu, Gentoo) and Mac OSX. 
# 
# The default target is "make install", but it also provides "make test" and "make docs".

SHELL := /bin/bash
APIGEN_PATH = ~/Downloads/apigen
CURPATH := $(shell pwd -P)
export PATH=$(HOME)/bin:$(shell echo $$PATH)

default: install

install:
	# check dependencies
ifneq ($(shell which php > /dev/null 2>&1; echo $$?),0)
	$(error "Missing php-cli.")
endif	

ifneq ($(shell which npm > /dev/null 2>&1 > /dev/null; echo $$?),0)
	$(error "Missing npm.")
endif

	# install composer if we don't have it already
ifneq ($(shell which composer > /dev/null 2>&1; echo $$?),0) 
	mkdir -p ~/bin
	curl -sS https://getcomposer.org/installer | php -d detect_unicode=Off -- --install-dir=$(HOME)/bin --filename=composer
	chmod +x $(HOME)/bin/composer
endif

	# ./api
	cd "$(CURPATH)/api" && composer install
	mkdir -p "$(CURPATH)/api/app/storage"
	# chmod -R 755 "$(CURPATH)/api/app/storage"

	# ./commandline
	cd "$(CURPATH)/commandline" && composer install
	ln -sf "$(CURPATH)/commandline/bin/dl-api" "$(HOME)/bin/dl-api"
	chmod +x "$(CURPATH)/commandline/bin/dl-api" "$(HOME)/bin/dl-api"
	npm --prefix "$(CURPATH)/commandline/console" install "$(CURPATH)/commandline/console"

	# add bash_completion
ifneq ($(shell grep -q "commandline/bash_completion" $(HOME)/.bash{rc,_profile} > /dev/null 2>&1; echo $$?),0)
	echo "source $(CURPATH)/commandline/bash_completion" >> $(HOME)/.bash_profile
endif
	
	# add ~/bin to user PATH
ifneq ($(shell grep -q "~/bin" $(HOME)/.bash{rc,_profile} > /dev/null 2>&1; echo $$?),0)
	echo "export PATH=~/bin:\$$PATH" >> $(HOME)/.bash_profile
endif

	echo "Finished"

test:
	./api/vendor/bin/phpunit --configuration ./api/phpunit.xml

docs:
	mkdir -p ../dl-api-docs/
	php -d memory_limit=512M ${APIGEN_PATH}/apigen.php --destination ../dl-api-docs/ \
																--exclude */tests/* \
																--exclude */Tests/* \
																--source ./api/app/ \
																--source ./api/vendor/guzzle/guzzle/src \
																--source ./api/vendor/illuminate \
																--source ./api/vendor/doctrine \
																--source ./api/vendor/slim/slim/Slim \
																--source ./api/vendor/symfony
	open ../dl-api-docs/index.html

