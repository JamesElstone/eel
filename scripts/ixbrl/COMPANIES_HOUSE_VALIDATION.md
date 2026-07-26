# Companies House revised-accounts validation

Local XML parsing and Arelle validation are necessary, but they are not an
official Companies House filing result. The pre-submission command therefore
treats a missing Companies House TEST/LIVE result as a blocking `NOT RUN`
layer and returns `FAIL`.

## Required TEST workflow

1. Lock and approve the Year End workflow, including the revised-accounts
   disclosures and the approving director.
2. In the Companies House accounts workflow, record the exact-period original
   filing, electronic-revision eligibility, and variance explanation.
3. Set the accounts filing environment to `TEST`, configure the Companies
   House presenter/package and CompanyData credentials, and install a current
   verified XML Gateway schema snapshot.
4. Use **Prepare revised accounts**. EEL creates an immutable artifact,
   fingerprints it, applies its document policy, and runs Arelle. Do not edit
   that prepared file.
5. Run the local command against that exact artifact:

   ```powershell
   php scripts/ixbrl/validate-revised-accounts.php `
     path/to/prepared-revised-accounts.xhtml
   ```

   At this point an overall `FAIL` is expected solely when the mandatory
   Companies House layer has not run. Resolve any other errors before sending.
6. Use the Companies House transmission card to preflight and submit the
   prepared artifact to the TEST XML Gateway. Preserve the request and response
   evidence created by EEL.
7. Poll status, send `StatusAck`, and repeat only as directed by the protocol
   workflow until the TEST filing is accepted or rejected. Never automatically
   resend after an uncertain transport outcome.
8. If accepted, retrieve and archive the Companies House document/preview.
   If rejected, retain every error and warning and correct the generator or
   source accounting data; do not suppress the response.
9. Create a small result JSON next to (or pointing to) the preserved official
   `GetSubmissionStatus` response. The response must contain the status for the
   declared submission number and that status must be `ACCEPT`:

   ```json
   {
     "official": true,
     "environment": "TEST",
     "status": "accepted",
     "validator": "Companies House XML Gateway",
     "validated_at": "2026-07-26T12:00:00Z",
     "artifact_sha256": "<sha256 of the exact XHTML sent>",
     "errors": [],
     "warnings": [],
     "submission_number": "000001",
     "response_transaction_id": "<TransactionID from the response>",
     "response_artifact": "preserved-get-submission-status-response.xml",
     "response_artifact_sha256": "<sha256 of the preserved response XML>",
     "preview_artifacts": [
       "path/to/preserved/companies-house-preview.pdf"
     ]
   }
   ```

   `status` must be `accepted`, `official` must be the Boolean `true`, and the
   artifact SHA-256 must match the inspected XHTML. The response path may be
   absolute or relative to the result JSON. Hash the exact response bytes after
   archiving them; do not reformat the XML first.

   The command does not trust the JSON status by itself. It reads the preserved
   response with external entity/network resolution disabled, checks its
   SHA-256, confirms `Class=GetSubmissionStatus` and `Qualifier=response`,
   selects the declared submission number, and requires its latest
   `StatusCode` to be `ACCEPT`. Fatal gateway errors, rejection details,
   missing responses, altered responses, missing or mismatched transaction
   IDs, and a response company number that differs from the iXBRL entity all
   block release. Gateway warnings are retained as `PASS WITH WARNINGS`.

   A locally invented result is not acceptable evidence. XML response files
   are not cryptographically signed by Companies House, so provenance still
   depends on preserving the immutable request/response archive created by the
   EEL transmission workflow.
10. Rerun the command with that result:

    ```powershell
    php scripts/ixbrl/validate-revised-accounts.php `
      path/to/prepared-revised-accounts.xhtml `
      --companies-house-result path/to/companies-house-result.json
    ```

The command embeds the verified response path, response hash, submission
status, gateway timestamp and transaction ID in its pre-submission report. The
source gateway response and preview remain separate immutable evidence and
must be retained with the filing build.

## Release rule

- `PASS`: every mandatory local layer, Arelle, and official Companies House
  validation passed with no warnings.
- `PASS WITH WARNINGS`: every mandatory layer completed, no errors remain, and
  at least one warning requires documented review.
- `FAIL`: any mandatory layer failed or did not run.

The command exits non-zero for `FAIL`. It exits zero for `PASS` and
`PASS WITH WARNINGS`; release policy must still require a human review of all
warnings.

Do not use a successful XML parse, browser preview, or Arelle result as evidence
that Companies House accepted the filing.
