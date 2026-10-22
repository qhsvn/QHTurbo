# QHturbo website

**English** · [Tiếng Việt](README.vi.md)

`index.html` is the whole site: one self-contained page (English and Vietnamese, light and dark theme), no build step. Open it in a browser to preview.

Published to <https://qhsvn.github.io/QHTurbo/> by `.github/workflows/pages.yml` on every push to `main` that changes `website/`. One-time setup in the GitHub repository: **Settings → Pages → Build and deployment → Source: GitHub Actions**.

When you change a command, option or version in the docs, update the same text in `index.html`. The install steps per platform live in the JSON block `finder-data` near the end of the file.
