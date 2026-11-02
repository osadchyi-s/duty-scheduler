---
name: localization
description: How UI texts are localized in duty-scheduler (English source strings in lang/en.json, Ukrainian in lang/uk.json, plural forms, what is not translated). Use whenever you add or change any user-visible text, notification, label or validation message.
---

# Localization

The panel supports Ukrainian (default) and English. The locale is stored in
`app_settings` and applied by `ApplyConfiguredLocale` and `DutyMatrix::boot()`.

## Adding a string

1. Write the English text in code: `__('Draft approved')`,
   `__('Deleted :count events.', ['count' => $n])`.
2. Add the same key to **both** files, keeping JSON formatting
   (4 spaces, unescaped Unicode, trailing newline):
   - `lang/en.json`: `"Draft approved": "Draft approved"`
   - `lang/uk.json`: `"Draft approved": "Чернетку затверджено"`
3. Reuse an existing key when the meaning is the same; check first.
4. Remove keys you made unused, from both files.

Find keys used in code but missing in `lang/en.json`:

```bash
python3 - <<'EOF'
import re, json, subprocess
files = subprocess.check_output("git ls-files -co --exclude-standard app resources database | grep '\\.php$'", shell=True, text=True).split()
pat = re.compile(r"""(?:__|trans_choice)\(\s*(['"])((?:\\.|(?!\1).)*)\1""", re.S)
keys = {m.group(2).replace("\\'", "'") for f in files for m in pat.finditer(open(f).read())}
en = json.load(open('lang/en.json'))
print("\n".join(sorted(k for k in keys if k not in en)))
EOF
```

Edit the JSON files with a script that preserves key order
(`json.load(..., object_pairs_hook=OrderedDict)`, then
`json.dumps(d, ensure_ascii=False, indent=4) + "\n"`), so diffs stay small.

## Ukrainian specifics

- Plurals have three forms: `":count day|:count days"` →
  `":count доба|:count доби|:count діб"`. `trans_choice()` only accepts integers;
  see `TimeUnit::format()` for fractional values.
- Use the typographic apostrophe `’` and quotes `«…»` in Ukrainian texts.
- Labels for days in forms and tables come in pairs for both time units
  (e.g. `Shift duration (days)` / `Shift duration (hours)`), chosen with
  `TimeUnit::usesDays()`.

## Not translated

- Database field names and stored values on the activity log page: the log is
  a technical tool; only the page UI (titles, filters, action labels) is localized.
- Stored texts such as `user_events.warning_reason` are saved in the locale
  that was active when the event was saved.
- Code, comments, identifiers, log event keys (`draft_approved`) and commit messages are English.
