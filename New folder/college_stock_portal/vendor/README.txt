Optional no-Composer libraries:

1. PhpSpreadsheet:
   Download PhpSpreadsheet and its dependencies, then place the autoloader at:
   vendor/autoload.php
   The app will use PhpSpreadsheet automatically when available.

2. TCPDF or FPDF:
   Place TCPDF at vendor/tcpdf/tcpdf.php or FPDF at vendor/fpdf/fpdf.php.
   The app includes a lightweight fallback PDF writer so reports still work in WAMP.

