<?php
declare(strict_types=1);

// Automatic Class Loader
require_once __DIR__ . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';


// Get http request details
$request = RequestFramework::fromGlobals();


$pageFactory = new PageFactoryFramework();


$page = $pageFactory->create($request->getPage());


$config = AppConfigurationStore::config();


$uploadBasePath = (string)($config['uploads']['upload_base_dir'] ?? '');


$appServices = new AppService(db(), $uploadBasePath);


$pageServices = new PageServiceFramework($appServices->getMany($page->services()));


$response = $page->handle($request, $pageServices);


$response->send();


