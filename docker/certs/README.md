# Local HTTPS certificate

The certificate files are intentionally excluded from Git. Generate them once on each development machine with [mkcert](https://github.com/FiloSottile/mkcert):

```sh
mkcert -install
mkcert -cert-file docker/certs/blog-api.local.pem \
  -key-file docker/certs/blog-api.local-key.pem \
  blog-api.local admin.blog-api.local
```

Then start the stack with `docker compose up -d`. The site is available at `https://blog-api.local` and HTTP redirects to HTTPS.

For hot reloading, run `./vendor/bin/sail npm run dev`; Vite is served at `https://blog-api.local:5173` and uses the same certificate.
