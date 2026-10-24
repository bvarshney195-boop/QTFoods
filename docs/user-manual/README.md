# ERP roles and permissions user manual

The published manual is [`../QT_FOODS_ERP_ROLES_PERMISSIONS_USER_MANUAL.pdf`](../QT_FOODS_ERP_ROLES_PERMISSIONS_USER_MANUAL.pdf).

Source files:

- `index.html` contains the manual content and the validated 69-screen role-access matrix.
- `manual.css` contains the A4 landscape print design.
- `screenshots/` contains 22 role-specific captures from the local seeded ERP.

To refresh screenshots, first run the normal local backend and frontend, then run from `FrontEnd/`:

```powershell
node scripts/capture-user-manual-screenshots.mjs
```

To validate the source and regenerate the 45-page tagged PDF:

```powershell
node scripts/render-user-manual-pdf.mjs
```

The renderer fails if the access matrix is not 69 rows, a screenshot is missing, the role screen totals differ from the seeded baseline, or a designed sheet exceeds the print area. Review and approve the content again whenever a role, permission, screen, workflow, or user-facing label changes.
