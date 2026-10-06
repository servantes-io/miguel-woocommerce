# CLAUDE.md

## Git workflow

- **Updating a feature branch from its base branch** (the branch its pull request targets): **rebase**
  onto it — `git fetch origin && git rebase origin/<base>`, then `git push --force-with-lease`. Never
  merge the base branch into the feature branch.
- **Integrating a feature branch into its base branch: merge** with a merge commit (`git merge --no-ff`,
  or "Create a merge commit" on the pull request) — not rebase-and-merge or squash.
