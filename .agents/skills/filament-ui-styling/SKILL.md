---
name: filament-ui-styling
description: Styling rules for Filament pages and Blade views in duty-scheduler, where there is no Tailwind build and many utility classes silently do nothing. Use when changing colors, layout or highlights in Blade views, custom form fields or Filament components.
---

# Filament UI styling

## The core problem

The project has **no Tailwind build step**. The only CSS is Filament's
precompiled `public/css/filament/filament/app.css`, which contains just the
utilities Filament itself uses. Classes like `bg-red-50`, `bg-yellow-100`,
`text-amber-600`, `border-r` or `hover:bg-primary-100` are **not** there and
render nothing, without any error. This already hid the weekend, rest-zone and
staffing-deficit highlights of the matrix for months.

Layout utilities are affected too: `sticky` exists but `left-0`, `table-fixed`
and arbitrary values like `w-[150px]` or `text-[11px]` do not. The matrix pins
its number and employee columns with `dm-col-num` / `dm-col-name` (explicit
`left` offsets) for this reason.

Check a class before using it:

```bash
grep -qF '.bg-red-50' public/css/filament/filament/app.css && echo exists || echo MISSING
# escape ':' and '/' as in CSS: '.dark\:bg-gray-800', '.bg-red-50\/50'
```

## What to use instead

1. **Filament components** (`<x-filament::button>`, `<x-filament::badge>`,
   `<x-filament::tabs>`, `Forms\Components\*`, table columns with `->color()`),
   which are styled by Filament's CSS and support light and dark themes.
2. **Custom classes in the view's `<style>` block**, prefixed per view. The
   duty matrix uses `dm-*` classes (`dm-vline`, `dm-weekend`, `dm-rest-min`,
   `dm-deficit-head`, `dm-past`, ...) with a `.dark` variant for each:

   ```css
   .dm-weekend       { background-color: rgb(249 250 251); }
   .dark .dm-weekend { background-color: rgb(255 255 255 / .03); }
   ```

   Rule order matters: later rules win at equal specificity (rest zones are
   defined after weekends so they override them).
3. **Inline styles** for one-off cases (custom form fields, small badges).
4. **Filament color variables** keep the panel palette: `rgb(var(--primary-500))`,
   `rgb(var(--danger-500))`, `rgb(var(--gray-400))`.

`tests/Feature/MatrixHighlightsTest.php` fails if `duty-matrix.blade.php` uses
a color utility missing from the Filament CSS. Extend that kind of check when
you add colors to other views.

## Other conventions

- The panel uses full content width (`maxContentWidth(MaxWidth::Full)` in
  `AdminPanelProvider`); do not add per-page width overrides.
- Edit pages duplicate the save button at the top
  (`$this->getSaveFormAction()->formId('form')` in header actions).
- Bulk deletes use `App\Filament\Actions\DeleteAllowedBulkAction`, which skips
  records the policy does not allow deleting instead of failing on database
  restrictions.
- Visual states in the matrix: inactive employees and past dates are dimmed;
  understaffed days have a red header cell with a tooltip; staging shows an
  amber banner (`APP_ENV=staging`).
- No UI words where the user asked for pure visuals (e.g. the synergy
  switch is three colored buttons without labels).
