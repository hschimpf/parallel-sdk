segfault: build
	docker run --rm -it segfault

build:
	docker build . -t segfault

clean:
	docker rmi segfault
