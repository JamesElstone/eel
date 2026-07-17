# HMRC CT600 RIM artefacts

The Corporation Tax submission code validates against HMRC's official CT600
V3 RIM bundle. The bundle is deliberately not committed. Install the pinned
V1.994 release with:

```powershell
./third_party/hmrc_ct600/bin/install_hmrc_ct600.ps1
```

The installer verifies the published ZIP's SHA-256 before extracting it under
`third_party/hmrc_ct600/runtime/HMRC-CT-2014-v1-994/`. The application fails
closed when the XSD, envelope schema, or Schematron XSLT is absent.

Runtime configuration belongs in the instance configuration, not source:

```php
'runtime' => [
    'hmrc_ct_environment' => 'TEST', // TEST, TIL, or LIVE
],
'hmrc' => [
    'ct600_xml' => [
        'vendor_id' => '0000',
        'product' => 'EEL Accounts',
        'version' => '1.0',
        'credential_provider' => 'HMRC',
        'credential_tag' => 'CT600_XML',
        'test_company_ids' => [9100], // deterministic synthetic companies only
        'live_enabled' => false,
        'sdst_assurance_confirmed' => false,
        'live_company_ids' => [], // add only after CT Online enrolment is confirmed
    ],
],
```

Dedicated XML credentials supplied by HMRC SDST use the existing protected
`api.keys` format. Store the Sender ID and password as `SenderID:Password`:

```csv
HMRC,CT600_XML,TEST,HTTPS,test-transaction-engine.tax.service.gov.uk,SenderID:Password
HMRC,CT600_XML,LIVE,HTTPS,transaction-engine.tax.service.gov.uk,SenderID:Password
```

Do not use Developer Hub REST sandbox credentials. Do not send real company
data to the External Test Service; use TIL with live credentials for a real
return that must be validated without registering it.

PHP `ext-xsl` must be enabled in the web runtime so the pinned HMRC compiled
Schematron transform can run. The application fails closed with
`XSL_EXTENSION_MISSING` when it is unavailable. LIVE remains disabled unless
all three server-controlled gates (`live_enabled`, SDST assurance, and the
company allowlist) are satisfied.
