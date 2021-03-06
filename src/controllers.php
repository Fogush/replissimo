<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

//Request::setTrustedProxies(['127.0.0.1']);

$app->get('/', function () use ($app) {    
    $databases = $app['database_helper']->getAllowedDatabases();

    return $app['twig']->render('index.html.twig', ['databases' => $databases]);
})->bind('homepage');

$app->get('/copies', function () use ($app) {
    $databases = $app['database_helper']->getInternalDatabases();

    return $app['twig']->render('copies.html.twig', ['databases' => $databases]);
})->bind('copies');

$app->post('/run', function (Request $request) use ($app) {
    try {
        $databaseHelper = $app['database_helper'];

        $databaseToCopy = $request->get('database');
        $userName = $request->get('user_name');
        $newDatabase = $databaseHelper->getNewDatabaseName($databaseToCopy, $userName);

        if ($databaseHelper->isDatabaseNameValid($newDatabase)) {
            return new Response("Name '$newDatabase' is invalid", 400);
        }

        if (!in_array($databaseToCopy, $databaseHelper->getAllowedDatabases())) {
            return new Response("Database '$databaseToCopy' doesn't exist on the server or not allowed", 400);
        }
        if (in_array($newDatabase, $databaseHelper->getInternalDatabases())) {
            return new Response(
                "Database '$newDatabase' already exists. You have to use another name or delete it.",
                400
            );
        }

        $databaseHelper->createDatabase($newDatabase);

        $connectionSettings = $app['config']['database'];
        $app['dump_runner']->copyDatabase($databaseToCopy, $newDatabase, $connectionSettings);

        return new Response('', 200);
    } catch (Exception $exception) {
        return new Response($exception->getMessage(), 400);
    }
})->bind('run');

$app->get('/check', function (Request $request) use ($app) {
    try {
        $databaseHelper = $app['database_helper'];

        $databaseToCopy = $request->get('database');
        $userName = $request->get('user_name');
        $newDatabase = $databaseHelper->getNewDatabaseName($databaseToCopy, $userName);

        if ($databaseHelper->isDatabaseNameValid($newDatabase)) {
            return $app->json(['finished' => false, 'resultMessage' => "Name '$newDatabase' is invalid"], 400);
        }
        if (!in_array($newDatabase, $databaseHelper->getInternalDatabases())) {
            $resultMessage = "Database '$newDatabase' doesn't exist on the server.";
            return $app->json(['finished' => false, 'resultMessage' => $resultMessage], 400);
        }

        //Returns: running - nothing,
        //not running and there are logs - an error occurred,
        //not running and no logs - finished successfully
        if ($app['dump_runner']->isDumpRunning($newDatabase)) {
            return $app->json(['finished' => false, 'resultMessage' => '']);
        }

        if ($logs = $app['dump_runner']->getRunLogs($newDatabase)) {
            $resultMessage = 'Something went wrong: <br>' . nl2br($logs);
            $status = 400;
        } else {
            $resultMessage = "Database '$databaseToCopy' has been copied into '$newDatabase'. Perfetto!";
            $status = 200;
        }
        return $app->json(['finished' => true, 'resultMessage' => $resultMessage], $status);
    } catch (Exception $exception) {
        $resultMessage = 'Something went wrong: <br>' . nl2br($exception->getMessage());
        return $app->json(['finished' => false, 'resultMessage' => $resultMessage], 400);
    }
})->bind('check');


$app->delete('/drop', function (Request $request) use ($app) {
    try {
        $databaseHelper = $app['database_helper'];

        $database = $request->get('database');

        if ($databaseHelper->isDatabaseNameValid($database)) {
            return new Response("Name '$database' is invalid.", 400);
        }
        if (!in_array($database, $databaseHelper->getInternalDatabases())) {
            return new Response("Database '$database' can't be deleted as it's not internal.", 400);
        }

        $databaseHelper->dropDatabase($database);

        return new Response("Database '$database' was successfully deleted", 200);
    } catch (Exception $exception) {
        return new Response($exception->getMessage(), 400);
    }
})->bind('drop');

$app->error(function (\Exception $e, Request $request, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    // 404.html, or 40x.html, or 4xx.html, or error.html
    $templates = [
        'errors/'.$code.'.html.twig',
        'errors/'.substr($code, 0, 2).'x.html.twig',
        'errors/'.substr($code, 0, 1).'xx.html.twig',
        'errors/default.html.twig',
    ];

    return new Response($app['twig']->resolveTemplate($templates)->render(['code' => $code]), $code);
});
