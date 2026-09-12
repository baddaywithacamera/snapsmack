<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# BLOGGER FLOGGER

**Get your Blogger archive. Flog it into SMACKTALK. Keep the photographs.**

BLOGGER FLOGGER is the inbound Blogger migration tool. It opens a Google Takeout
ZIP, `feed.atom`, or historical Blogger backup XML on your computer, lets you
review what is there, then imports selected posts, pages, comments, labels,
dates, drafts, and photographs into a SMACKTALK site.

It does not log into Blogger and does not export from SnapSmack. TAKE YOUR SHIT
WITH YOU owns outbound formats.

## Before importing

1. Download Blogger through Google Takeout.
2. In the destination SnapSmack site, generate a **BLOGGER FLOGGER** API key.
3. If the destination already has more than five items, authorize imports for
   one hour from the same API Keys page.
4. Open BLOGGER FLOGGER, choose the Takeout archive, review the inventory, choose
   the SMACKTALK destination, and test it.
5. Keep the default “draft” policy unless you deliberately want imported Blogger
   posts published immediately.

Every imported source ID receives a destination-side receipt. Restarting or
rerunning the same archive does not create duplicates. The final job directory
contains a JSON manifest and readable HTML reconciliation report.

## Development

```text
C:\dev\snapsmack\.python-build\python.exe -m unittest discover -s tests -v
C:\dev\snapsmack\.python-build\python.exe app.py
```

The release gate requires a current, anonymized real Google Takeout fixture and
a live import into a clean SMACKTALK test site. Synthetic tests alone are not a
compatibility claim.

<!-- ===== SNAPSMACK EOF ===== -->
