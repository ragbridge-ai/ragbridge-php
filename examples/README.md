# Examples

The examples show `ragbridge/php` in a small application. They are not part of the package:
they are excluded from the Composer archive and are never autoloaded.

## basic

A single PHP page, served by PHP's built-in web server, that lets you upload a document,
ask questions about it, list the stored documents and delete them.

You need PHP 8.2 or later, Composer and a running ragbridge service with an API key. The
[quick start](../docs/quickstart.md) explains how to start one.

```bash
cd examples/basic
composer install

export RAGBRIDGE_BASE_URL=http://localhost:8000
export RAGBRIDGE_API_KEY=rb_your-key
php -S localhost:8080 -t public
```

Open <http://localhost:8080>, upload a text, Markdown or PDF file, and ask a question about
it. The answer is shown together with the parts of your documents that it is based on.

`composer install` links `ragbridge/php` from this repository (a Composer path repository),
so changes you make to the package show up immediately. In your own project you would
install it from Packagist with `composer require ragbridge/php`.

The example is a demonstration. It has no authentication, no CSRF protection and no upload
limits of its own, so do not expose it to a network you do not trust.
