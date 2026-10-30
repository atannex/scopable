# Atannex Scopable

Gives Laravel models ready-made states and date filters. You declare once what published, scheduled, draft, active or expired means for a model, and get matching database queries (such as published() or createdThisWeek()), the same checks on a single record, and events when a record changes state. It can also add matching tabs and filters to Filament admin pages.

## Install

```bash
composer require atannex/scopable
```
