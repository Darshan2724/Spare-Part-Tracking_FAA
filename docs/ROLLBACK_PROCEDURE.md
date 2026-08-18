# Emergency Rollback & Recovery Guide

This procedure allows rolling back a faulty deployment in under 60 seconds.

---

## 1. Quick Code Rollback

To revert code to the previous Git commit:

```powershell
cd C:\SpareTrack
.\scripts\rollback.ps1
```

The script will:
1. Display the recent Git commits.
2. Prompt for the target commit hash (or default to `HEAD~1`).
3. Check out the previous known-good commit.
4. Rebuild the application containers.
5. Clear and refresh caches.

---

## 2. Code + Database Rollback

If a bad deployment included destructive database migrations:

```powershell
.\scripts\rollback.ps1 -RestoreDatabase
```

This will revert the codebase and automatically launch `restore.ps1` to restore the pre-deployment database snapshot.
