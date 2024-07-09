ifndef PHP
override PHP = 8.0
endif

segfault: build
	docker run --rm -it segfault

build:
	docker build . --build-arg PHP_VERSION=$(PHP) -t segfault

clean:
	docker rmi segfault

.PHONY: build clean
