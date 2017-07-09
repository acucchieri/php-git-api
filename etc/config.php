<?php

use Symfony\Component\Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__.'/../.env');
}

$app['debug'] = getenv('DEBUG') ?: true;
$app['env'] = getenv('ENV') ?: 'dev';
$app['git'] = array(
    'scheme' => getenv('GIT_SCHEME') ?: 'https',
    'domain' => getenv('GIT_DOMAIN') ?: 'localhost',
    'repos_path' => getenv('GIT_REPOS_PATH') ?: '/opt/git',
    // 'git_path' => ''
);
