# Claude in This Repository

- Call the user **Mahler**.
- This repository is **public**. Everything committed here, and every issue, pull request, and commit message, is visible to anyone. Keep out internal operating detail (tooling, hosts, workflows outside this repo, other projects or repositories that aren't public) and email addresses.
- Change this repository by pull request, never by a push to `main`. Pull requests are squash-merged with the PR title as the commit message, so write a clean, plain title.
- Never commit secrets, tokens, API keys, `.env` contents, or test credentials. Secret scanning and push protection are on; don't bypass a block.
- Follow the WordPress coding and security standards: escape output, sanitize input, check capabilities and nonces, and prefix everything with `bristlecone_zfolio_` / `BRISTLECONE_ZFOLIO_` / `Bristlecone\Zfolio\` (slug and text domain `bristlecone-zfolio`).
- Keep development-only files out of the release zip by listing them in `.distignore` and `.gitattributes`.
