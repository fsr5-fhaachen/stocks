# Contribution Guidelines

This file contains all relevant information about contributing to this repository.
Please read it carefully before contributing.

## Tooling

We are using the following tools.
These are only recommendations and not mandatory.

- [Visual Studio Code](https://code.visualstudio.com/) (for configuration see [`settings.json`](./.vscode/settings.json) and [`extensions.json`](./.vscode/extensions.json))
- PostgreSQL, MariaDB or other DB that works with Laravel
- PHP
- Webserver (Apache, Nginx, Swoole über Octane, ...)
- Composer
- Node.js
- TypeScript
- ...

> [!TIP]
> If you don't want to setup your own environment, you can use the provided [DevContainer](#devcontainer).

### DevContainer

If you want to use Visual Studio Code, all of our tooling, preinstalled extensions and configured settings you can use the provided [DevContainer](./.devcontainer).

To use the DevContainer you need to install the [Remote - Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) extension.

## Branches

This repository has two "relevant" branches: `main` and `dev`.

Every pull-request that implements a feature, fix or other changes must be created from the `dev` branch. The target branch of the pull request must also be the `dev` branch.

### Branch Naming

Every branch should follow the following pattern:

- `feat/*` for new features
- `fix/*` for bug fixes

and so on. You can find more information about the possible types in the [Commits](#commits) section.

Every branch should only target one issue or feature.

## Commits

We are using [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) for our commits.
Every commit must follow this pattern.

The commit message is split into different parts:

- **Commit type**. (e.g. `feat`, `fix`, `chore`, `ci`, `test`, ...)
- **Changed module/component** in brackets. If you want to target a sub module/component you can add it after the module/component and separate it with a slash `/`.
  Example: `(devcontainer)` or `(tests/e2e)`. If no module/component fits, you can leave this part out completely.
  Please don't use `*` as module/component or add empty brackets `()`.
- **A colon `:`** to separate the type and module/component from the commit message.
- **Commit message**. All messages must be in english and follow the conventions of the conventional commits standard.
  They must be written in the imperative, present tense. Example: "change" not "changed" nor "changes". The first letter should not be capitalized.

The resulting commit message should look like this:

- `feat(devcontainer): add documentation`
- `fix: remove typo`

### Formatting hook

`npm install` turns on the hook in [`.githooks/pre-commit`](./.githooks/pre-commit) (script `prepare` sets `core.hooksPath`).
Before every commit it formats the staged files with Prettier (same files as `npm run lint`) and PHP files with Pint, and stages them again, so the lint steps of the CI pass.
A file that also has unstaged changes is only checked; if it is not formatted, the commit stops with a hint.
It also works in GUI clients like GitKraken (it loads Node from nvm or Homebrew itself). Pint needs a local `php`; with Sail only, the CI checks PHP.
Skip it once with `git commit --no-verify` or "Commit and skip hooks" in GitKraken.

## Pull Requests

If you have completed your development on a branch, you have to create a pull request.
The pull request must be pointed against the `dev` branch (see [Branches](#branches)).

The pull request title should be in the same pattern as the commit messages (see [Commits](#commits)), because the pull request title is used as the merge commit message.

Please update the labels of the PR accordingly.

After submitting the pull request, it will be reviewed and if everything is fine, it will be merged into the `dev` branch.

Every change to the `dev` branch will be merged to the `main` branch later on.

## Releases

We are using [Semantic Versioning](https://semver.org/) for our releases.

Releases are created from the `main` branch.

## Automated and AI-assisted contributions

- Pull requests must be opened and **understood by a human** who is
  responsible for them. You must be able to explain every change in your PR
  and respond to review feedback yourself.
- **Fully automated accounts and agents are not allowed** to comment on
  issues, claim issues, or open pull requests in this repository without prior
  written permission from the maintainers.
- AI-assisted code is fine if you have reviewed, tested, and understood it.
  Please disclose significant AI assistance in the PR description.
- This repository is not part of any external benchmark, evaluation, or
  ranking program. Contributions made to improve statistics or rankings are
  not accepted.
