<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;


/**
 * File content.
 *
 * @param string $name Repository name
 * @param string $sha  File hash
 */
$app->get('/repositories/{name}/files/{sha}/contents', function (Request $request, $name, $sha) use ($app) {
    $row = $app['git.client']->getFileContents($name, $sha);

    return $app->json([
        'size' => strlen($row['contents']),
        'name' => end(explode('/', $row['filename'])),
        'path' => $row['filename'],
        'content' => $row['contents'],
    ]);
})
    ->assert('name', '.+')
    ->assert('sha', '[a-fA-F0-9]{5,40}')
    ->bind('get_file_contents')
;

/**
 * File history.
 *
 * @param string $name Repository name
 * @param string $sha  Filename
 */
$app->get('/repositories/{name}/files/{sha}/history', function (Request $request, $name, $sha) use ($app) {
    $limit = $request->query->get('limit', 10);
    $offset = $request->query->get('offset', 0);

    $commits = $app['git.client']->getFileHistory($name, $sha, $limit, $offset);
    $count = $app['git.client']->countFileCommits($name, $sha);

    return $app->json([
        'count' => $count,
        'limit' => $limit,
        'offset' => $offset,
        'commits' => $commits,
    ]);
})
    ->assert('name', '.+')
    ->assert('sha', '[a-fA-F0-9]{5,40}')
    ->bind('get_file_history')
;

/*
 * Get a tree.
 *
 * <code>
 * \GET  /repositories/{name}/trees/{revision}
 * </code>
 *
 * Get a tree recursively.
 *
 * <code>
 * \GET  /repositories/{name}/trees/{revision}?recursive=1
 * </code>
 *
 * @param string $name      Repository name
 * @param string $revision  Revision (tree) name
 *
 */
$app->get('/repositories/{name}/trees/{revision}', function (Request $request, $name, $revision) use ($app) {
    $recursive = $request->query->get('recursive', 0);
    $tree = $app['git.client']->getTree($name, $revision, $recursive);

    return $app->json($tree);
})
    ->assert('name', '.+')
    ->bind('get_tree')
;

/*
 * Download an archive of files from a named tree.
 *
 * <code>
 * \GET  /repositories/{name}/tar_ball/{revision}
 * \GET  /repositories/{name}/zip_ball/{revision}
 * </code>
 *
 * @param string $name      Repository name
 * @param string $format    Archive format (tar|zip)
 * @param string $revision  Revision (tree) name
 */
$app->get('/repositories/{name}/{format}_ball/{revision}', function ($name, $format, $revision) use ($app) {
    $filename = $app['git.client']->archive($name, $revision, $format);
    $file = sprintf('%s.%s',
        ('HEAD' === $revision) ? 'latest' : $revision,
        $format
    );

    return $app->sendFile($filename)
        ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file)
    ;
})
    ->assert('name', '.+')
    ->assert('format', '(tar|zip)')
    ->bind('archive')
;

/*
 * Get a tag.
 *
 * <code>
 * \GET  /repositories/{name}/tags/{tag}
 * </code>
 *
 * @param string $name   Repository name
 * @param string $tag    The tag
 */
$app->get('/repositories/{name}/tags/{tag}', function ($name, $tag) use ($app) {
    $tag = $app['git.client']->getTag($name, $tag);

    return $app->json($tag);
})
    ->assert('name', '.+')
    ->bind('get_tag')
;

/*
 * List tags.
 *
 * <code>
 * \GET  /repositories/{name}/tags
 * </code>
 *
 * @param string $name   Repository name
 */
$app->get('/repositories/{name}/tags', function ($name) use ($app) {
    $tags = $app['git.client']->getTags($name);

    return $app->json($tags);
})
    ->assert('name', '.+')
    ->bind('get_tags')
;

/*
 * Get a commit.
 *
 * <code>
 * \GET  /repositories/{name}/commits/{sha}
 * </code>
 *
 * @param string $name   Repository name
 * @param string $sha    Commit sha hash
 */
$app->get('/repositories/{name}/commits/{sha}', function ($name, $sha) use ($app) {
    $commit = $app['git.client']->history($name, 1, 0, $sha);

    return $app->json($commit[0]);
})
    ->assert('name', '.+')
    // ->assert('sha', '[a-fA-F0-9]{5,40}')
    ->bind('get_commit')
;

/*
 * List commits.
 *
 * <code>
 * \GET  /repositories/{name}/commits
 * </code>
 *
 * @param string $name   Repository name
 */
$app->get('/repositories/{name}/commits', function ($name) use ($app) {
    $commits = $app['git.client']->history($name);

    return $app->json($commits);
})
    ->assert('name', '.+')
    ->bind('get_commits')
;

/*
 * Show commit logs.
 *
 * <code>
 * \GET  /repositories/{name}/history
 * </code>
 *
 * Filtering search
 *
 * <code>
 * \GET  /repositories/{name}/history?limit={limit}&offset={offset}
 * </code>
 *
 * @param string $name   Repository name
 */
$app->get('/repositories/{name}/history', function (Request $request, $name) use ($app) {
    $limit = $request->query->get('limit', 10);
    $offset = $request->query->get('offset', 0);

    $commits = $app['git.client']->history($name, $limit, $offset);
    $count = $app['git.client']->countCommits($name);

    return $app->json([
        'count' => $count,
        'limit' => $limit,
        'offset' => $offset,
        'commits' => $commits,
    ]);
})
    ->assert('name', '.+')
    ->bind('history')
;

/*
 * Get a repository.
 *
 * <code>
 * \GET  /repositories/{name}
 * </code>
 *
 * @param string $name   Repository name
 */
$app->get('/repositories/{name}', function ($name) use ($app) {
    $repository = $app['git.client']->getRepository($name);

    return $app->json($repository);
})
    ->assert('name', '.+')
    ->bind('get_repository')
;

/*
 * List repositories.
 *
 * <code>
 * \GET  /repositories
 * </code>
 */
$app->get('/repositories', function () use ($app) {
    $repositories = $app['git.client']->getRepositories();

    return $app->json($repositories);
})
    ->bind('get_repositories')
;
