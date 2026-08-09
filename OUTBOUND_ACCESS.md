# Outbound access

This is the allow-list of static outbound HTTP(S) base URLs used by the application source. URLs are reduced to their scheme and host; paths and individual API operations are intentionally omitted.

- https://api.charitycommission.gov.uk/
- https://api.ipify.org/
- https://api.service.hmrc.gov.uk/
- https://assets.publishing.service.gov.uk/
- https://document-api.company-information.service.gov.uk/
- https://media.frc.org.uk/
- https://oscrapi.azurewebsites.net/
- https://resources.companieshouse.gov.uk/
- https://test-api.service.hmrc.gov.uk/
- https://test-transaction-engine.tax.service.gov.uk/
- https://transaction-engine.tax.service.gov.uk/
- https://www.charitycommissionni.org.uk/
- https://www.frc.org.uk/
- https://www.gov.uk/
- https://www.hmrc.gov.uk/
- https://xmlgw.companieshouse.gov.uk/

## Configurable endpoints

The SMS gateway URL is application configuration. Its default host is listed below, but an administrator can replace it with another HTTP(S) URL.

- https://sms.api.server/

The SMTP settings allow an administrator to configure an SMTP server host and TCP port, or to select PHP `mail()`. There is no fixed default SMTP host to list.

This list excludes browser-only links, XML/XBRL namespace identifiers, test fixtures, and third-party/dependency sources because the application does not make outbound calls to them as part of its runtime behaviour.
