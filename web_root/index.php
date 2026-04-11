<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$request = WebRequest::fromGlobals();
$pageFactory = new WebPageFactory();
$page = $pageFactory->create($request->getPage(), 'dashboard');

$config = FrameWorkHelper::config();
$uploadBasePath = (string)($config['uploads']['upload_base_dir'] ?? '');
$appServices = new AppServices(db(), $uploadBasePath);
$pageServices = new WebPageServices($appServices->getMany($page->services()));

$response = $page->handle($request, $pageServices);
$response->send();
