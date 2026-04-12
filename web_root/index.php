<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$request = WebRequest::fromGlobals();
$pageFactory = new WebPageFactory();
$page = $pageFactory->create($request->getPage(), 'dashboard');

$config = FrameworkHelper::config();
$uploadBasePath = (string)($config['uploads']['upload_base_dir'] ?? '');
$appServices = new AppService(db(), $uploadBasePath);
$pageServices = new WebPageService($appServices->getMany($page->services()));

$response = $page->handle($request, $pageServices);
$response->send();
