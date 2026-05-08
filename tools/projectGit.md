# projectGit

`projectGit` helps create a new project repo from eelKit while keeping eelKit available as an upstream Git remote.

This is useful when:

- eelKit has its own GitHub repo.
- The new project needs its own GitHub repo.
- eelKit updates should be importable into the project later.

## Files

```text
tools/php/projectGit.php
tools/bin/projectGit.sh
tools/bat/projectGit.bat
```

Use the `.sh` wrapper on Linux/macOS/Git Bash, or the `.bat` wrapper on Windows Command Prompt.

## New Project Setup

Start by cloning eelKit into the new project directory:

```bash
git clone https://github.com/JamesElstone/eelKit.git yourProjectRepo
cd yourProjectRepo
```

Then run:

```bash
tools/bin/projectGit.sh init git@github.com:you/yourProjectRepo.git
```

On Windows Command Prompt:

```bat
tools\bat\projectGit.bat init git@github.com:you/yourProjectRepo.git
```

This will:

1. Rename the current `origin` remote to `upstream`.
2. Add the new project GitHub repo as `origin`.
3. Push the current branch to the project repo.

After this, the remotes should look like:

```text
origin    git@github.com:you/yourProjectRepo.git
upstream  https://github.com/JamesElstone/eelKit.git
```

## Import eelKit Updates

From inside the project repo, run:

```bash
tools/bin/projectGit.sh import
```

On Windows Command Prompt:

```bat
tools\bat\projectGit.bat import
```

This will:

1. Fetch updates from `upstream`.
2. Merge `upstream/<current-branch>` into the current project branch.

For example, if the current branch is `main`, this imports:

```text
upstream/main
```

## Import With Rebase

If you prefer a linear history:

```bash
tools/bin/projectGit.sh import --rebase
```

On Windows Command Prompt:

```bat
tools\bat\projectGit.bat import --rebase
```

This fetches from `upstream`, then rebases the current branch onto `upstream/<current-branch>`.

## Custom Remote Or Branch

The default upstream remote name is `upstream`.

The default branch is the current branch.

To specify both:

```bash
tools/bin/projectGit.sh import upstream main
```

To rebase against a specific upstream branch:

```bash
tools/bin/projectGit.sh import upstream main --rebase
```

During initial setup, you can also specify the upstream remote name and branch:

```bash
tools/bin/projectGit.sh init git@github.com:you/yourProjectRepo.git upstream main
```

## Safety Checks

The tool checks that the Git worktree is clean before running setup, merge, or rebase operations.

If there are uncommitted changes, commit or stash them first:

```bash
git status
git add .
git commit -m "Describe your changes"
```

or:

```bash
git stash
```

Then run `projectGit` again.

## Typical Workflow

Create the project:

```bash
git clone https://github.com/JamesElstone/eelKit.git yourProjectRepo
cd yourProjectRepo
tools/bin/projectGit.sh init git@github.com:you/yourProjectRepo.git
```

Do project work as normal:

```bash
git add .
git commit -m "Build yourProjectRepo dashboard"
git push
```

Import eelKit updates later:

```bash
tools/bin/projectGit.sh import
git push
```
