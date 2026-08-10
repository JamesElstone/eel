## Transmission credentials

HMRC and Companies House transmission credentials are read from the private
`secure/api.keys` CSV through the credential store. Companies House TEST
accounts filing and HMRC CT600 XML use environment-specific Software Reference
values alongside their authentication credentials. The canonical header is
shown below; replace placeholder values locally and do not commit the file:


# API Keys
```tsv
Provider		Gateway	Tag	Environment	Schema	URL
CHARITYCOMMISSION	REST	CHARITY_LOOKUP	LIVE	HTTPS	api.charitycommission.gov.uk	
DVLA		REST	VEHICLE_LOOKUP	LIVE	HTTPS	??
COMPANIESHOUSE	REST	COMPANY_LOOKUP	LIVE	HTTPS	api.company-information.service.gov.uk	
COMPANIESHOUSE	REST	COMPANY_LOOKUP	TEST	HTTPS	api-sandbox.company-information.service.gov.uk	
COMPANIESHOUSE	XML	XML_PRESENTER	LIVE	HTTPS	xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway	
COMPANIESHOUSE	XML	XML_PRESENTER	TEST	HTTPS	xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway	
HMRC		REST	FPH_VALIDATOR	TEST	HTTPS	test-api.service.hmrc.gov.uk	
HMRC		REST	VAT_CHECK	TEST	HTTPS	test-api.service.hmrc.gov.uk	
HMRC		XML	CT600_XML	TEST	HTTPS	test-transaction-engine.tax.service.gov.uk	
OSCR		REST	CHARITY_LOOKUP	LIVE	HTTPS	oscrapi.azurewebsites.net	
```

Software Reference is visible metadata in the API Keys Editor, and is used to identify this software. These Software References are issued by HMRC and Companies House. API Identity and API Key remain write-only authentication values in the web UI.
