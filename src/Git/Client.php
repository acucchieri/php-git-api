<?php

namespace GitApi\Git;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Client
{
    private $config;
    private $router;

    public function __construct($config, UrlGeneratorInterface $router)
    {
        $this->config = $config;
        $this->router = $router;
        $this->config['repos_path'] = rtrim($this->config['repos_path'], '/');

    }

    /**
     * List repositories.
     *
     * @return array
     */
    public function getRepositories()
    {
        $finder = $this->repoFinder();

        $repositories = array();
        foreach ($finder as $repo) {
            $repositories[] = $this->repoMeta($repo);
        }

        return $repositories;
    }

    /**
     * Get a repository.
     *
     * @param string $name Repository name
     *
     * @return array
     */
    public function getRepository($name)
    {
        $path = $this->repoPath($name);

        return $this->repoMeta(new SplFileInfo($path, null, $name));
    }

    /**
     * Count repository commits for a revison.
     *
     * @param string $name     Repository name
     * @param string $revision Revision (HEAD, branch, tag). If null, count across all banches.
     *
     * @return int
     */
    public function countCommits($name, $revision = null)
    {
        $path = $this->repoPath($name);
        if ($revision && !$this->revisionExists($name, $revision)) {
            throw new HttpException(404, sprintf('%s is not a valid branch', $revision));
        }

        $command = sprintf('git --git-dir=%s rev-list --count %s',
            $path,
            ($revision) ?: '--all'
        );
        exec($command, $output, $return);

        return (isset($output[0])) ? (int)$output[0] : 0;
    }

    /**
     * Count commits for a file.
     *
     * @param string $name Repository name
     * @param string $sha  File hash
     *
     * @return int
     */
    public function countFileCommits($name, $sha)
    {
        if (!$this->revisionExists($name, $sha)) {
            throw new HttpException(404, sprintf('%s is not a valid revision', $sha));
        }

        $path = $this->repoPath($name);
        $filename = $this->hash2filename($name, $sha);

        $command = sprintf('git --git-dir=%s log --follow --oneline -- %s | wc -l',
            $path,
            $filename
        );
        exec($command, $output, $return);

        return (isset($output[0])) ? (int)$output[0] : 0;
    }

    /**
     * Get repository history log.
     *
     * @param string $name     Repository name
     * @param int    $limit    Maximum number of commits to return (-1 = all)
     * @param string $offset   Offset of the first commit to return
     * @param string $revision Revision (HEAD, branch, tag). If null, count across all banches.
     *
     * @return array
     */
    public function history($name, $limit = -1, $offset = 0, $revision = null)
    {
        $path = $this->repoPath($name);
        $skip = $limit + $offset;

        $command = sprintf('git --git-dir=%s log %s --pretty=format:%s --skip=%d --max-count=%d',
            $path,
            ($revision) ?: '',
            "'".$this->format('commit')."'",
            $skip,
            $limit
        );
        exec($command, $output, $return);

        return $this->parseCommit($name, $output);
    }

    /**
     * Get file commits history.
     *
     * @param string $name   Repository name
     * @param string $sha    File hash
     * @param int    $limit  Maximum number of commits to return (-1 = all)
     * @param string $offset Offset of the first commit to return
     *
     * @return array
     */
    public function getFileHistory($name, $sha, $limit = -1, $offset = 0)
    {
        if (!$this->revisionExists($name, $sha)) {
            throw new HttpException(404, sprintf('%s is not a valid revision', $sha));
        }

        $path = $this->repoPath($name);
        $filename = $this->hash2filename($name, $sha);
        $skip = $limit + $offset;

        $command = sprintf('git --git-dir=%s log --follow --pretty=format:%s --skip=%d --max-count=%d -- %s',
            $path,
            "'".$this->format('commit')."'",
            $skip,
            $limit,
            $filename
        );
        exec($command, $output, $return);

        return $this->parseCommit($name, $output);
    }

    /**
     * Get file contents.
     *
     * @param string $name Repository name
     * @param string $sha  File hash
     *
     * @return array
     */
    public function getFileContents($name, $sha)
    {
        if (!$this->revisionExists($name, $sha)) {
            throw new HttpException(404, sprintf('%s is not a valid revision', $sha));
        }

        $filename = $this->hash2filename($name, $sha);
        $contents = $this->run(sprintf('show %s --source', $sha), $name);

        return [
            'filename' => $filename,
            'contents' => $contents,
        ];
    }

    /**
     * List repository tags.
     *
     * @param string $name Repository name
     *
     * @return array
     */
    public function getTags($name)
    {
        $path = $this->repoPath($name);

        $command = sprintf('git --git-dir=%s for-each-ref --format=%s refs/tags | sort -V ',
            $path,
            "'".$this->format('tag')."'"
        );
        exec($command, $output, $return);

        $tags = array_map(function ($str) use ($name) {
            if ($row = json_decode($str, true)) {
                $row['url'] = $this->generateUrl('get_tag', array(
                    'name' => $name,
                    'tag' => $row['tag'],
                ));
                $row['object']['url'] = $this->generateUrl('get_commit', array(
                    'name' => $name,
                    'sha' => $row['object']['sha'],
                ));
                $row['tagger']['email'] = ltrim(rtrim($row['tagger']['email'], '>'),'<');

                return $row;
            }
        }, $output);

        return $tags;
    }

    /**
     * Get tag.
     *
     * @param string $name Repository name
     * @param string $tag  The tag
     *
     * @return array
     */
    public function getTag($name, $tag)
    {
        $path = $this->repoPath($name);

        if (!file_exists(sprintf('%s/refs/tags/%s', $path, $tag))) {
            throw new HttpException(404, sprintf('%s is not a valid tag', $tag));
        }

        $command = sprintf('git --git-dir=%s for-each-ref --format=%s refs/tags/%s',
            $path,
            "'".$this->format('tag')."'",
            $tag
        );
        exec($command, $output, $return);

        if ($row = json_decode($output[0], true)) {
            $row['url'] = $this->generateUrl('get_tag', array(
                'name' => $name,
                'tag' => $row['tag'],
            ));
            $row['object']['url'] = $this->generateUrl('get_commit', array(
                'name' => $name,
                'sha' => $row['object']['sha'],
            ));
        }

        return $row;
    }

    /**
     * Create an archive of files from a named tree.
     *
     * @param string $name     Repository name
     * @param string $revision Revision (tree) name
     * @param string $format   Archive format
     *
     * @return string archive filename
     */
    public function archive($name, $revision = 'HEAD', $format = 'tar')
    {
        $revision = ('latest' === $revision) ? 'HEAD' : $revision;
        if (!$this->revisionExists($name, $revision)) {
            throw new HttpException(404, sprintf('%s is not a valid revision', $revision));
        }

        $filename = '/tmp/';
        $filename .= ('HEAD' === $revision) ? 'latest' : $revision;
        $filename .= '.'.$format;

        $this->run(sprintf('archive -o %s %s', $filename, $revision), $name);

        return $filename;
    }

    /**
     * List the contents of a tree object.
     *
     * @param string $name      Repository name
     * @param string $revision  Revision (tree) name
     * @param string $recursive If TRUE, recurse into sub-trees
     *
     * @return string archive filename
     */
    public function getTree($name, $revision, $recursive)
    {
        if (!$this->revisionExists($name, $revision)) {
            throw new HttpException(404, sprintf('%s is not a valid revision', $revision));
        }

        $output = $this->run(sprintf('ls-tree -l%s %s',
            ($recursive) ? 'r' : '',
            $revision
        ), $name);

        $tpl = array('mode', 'type', 'sha', 'size', 'path');
        $nodes = array();
        foreach (explode("\n", $output) as $key => $node) {
            if ($node) {
                $row = preg_split("/[\s]+/", $node);
                if (count($row) === count($tpl)) {
                    $nodes[] = array_combine($tpl, $row);
                    if ('tree' === $nodes[$key]['type']) {
                        unset($nodes[$key]['size']);
                    }
                }

            }
        }

        return $nodes;
    }

    /**
     * Return filename for a given hash.
     *
     * @param string $name Repository name
     * @param string $sha  File hash
     *
     * @return string
     */
    public function hash2filename($name, $sha)
    {
        $type = $this->run(sprintf('cat-file %s -t', $sha), $name);
        if ('blob' !== rtrim($type, PHP_EOL)) {
            throw new HttpException(404, sprintf('%s is not a valid file hash', $sha));
        }

        $command = sprintf('rev-list --objects --all --oneline | grep %s', $sha);
        $output = $this->run($command, $name);

        return end(explode(' ', rtrim($output, PHP_EOL)));
    }

    /**
     * Returns the metadata descriptor for a repository.
     *
     * @param Symfony\Component\Finder\SplFileInfo $repo Repository
     *
     * @return array
     */
    private function repoMeta(SplFileInfo $repo)
    {
        $description = $repo->getRealPath().'/description';

        return array(
            'name' => end(explode('/', $repo->getRelativePathName())),
            'full_name' => $repo->getRelativePathName(),
            'description' => file_exists($description) ?
                rtrim(file_get_contents($description), PHP_EOL) :
                '',
            'updated_at' => date('c', $repo->getCTime()),
        );
    }

    /**
     * Search repositories.
     *
     * @param string $name Repository name
     *
     * @return Symfony\Component\Finder\Finder
     */
    private function repoFinder($name = null)
    {
        $isRepo = function (SplFileInfo $dir) {
            return file_exists($dir->getPathname().'/HEAD')
                && file_exists($dir->getPathname().'/objects')
                && file_exists($dir->getPathname().'/refs')
            ;
        };

        $finder = new Finder();
        $finder
            ->directories()
            ->in($this->config['repos_path'])
            ->ignoreUnreadableDirs()
            ->filter($isRepo)
            ->sortByName()
        ;
        if ($name) {
            $finder->name($name);
        }

        return $finder;
    }

    /**
     * Return repository path.
     *
     * @param string $name Repository name
     *
     * @return string
     */
    private function repoPath($name)
    {
        $path = $this->config['repos_path'].DIRECTORY_SEPARATOR.$name;

        if (!file_exists($path)) {
            throw new HttpException(404, sprintf('Repository %s does not exists', $name));
        }

        $isRepo = file_exists($path.'/HEAD')
            && file_exists($path.'/objects')
            && file_exists($path.'/refs')
        ;
        if (!$isRepo) {
            throw new \Exception(sprintf('%s is not a repository', $name));
        }

        return $path;
    }

    /**
     * Return git command format.
     *
     * @param string $key The format key
     *
     * @return string
     */
    private function format($key)
    {
        switch ($key) {
            case 'commit':
                // git log / rev-list format
                $format = [
                    'sha' => '%H',
                    'url' => '',
                    'author' => [
                        'name' => '%an',
                        'email' => '%ae',
                        'date' => version_compare($this->getVersion(), '2.2.0', '<') ? '%ai' : '%aI',
                    ],
                    'committer' => [
                        'name' => '%cn',
                        'email' => '%ce',
                        'date' => version_compare($this->getVersion(), '2.2.0', '<') ? '%ci' : '%cI',
                    ],
                    'message' => '%s',
                    'tree' => [
                        'sha' => '%T',
                        'url' => '',
                    ],
                    'parent' => [
                        'sha' => '%P',
                        'url' => '',
                    ],
                ];
                break;
            case 'tag':
                // git for-each-ref format
                $format = [
                    'tag' => '%(refname:short)',
                    'sha' => '%(objectname)',
                    'url' => '',
                    'message' => '%(subject)',
                    'tagger' => [
                        'name' => '%(taggername)',
                        'email' => '%(taggeremail)',
                        'date' => '%(taggerdate)',
                    ],
                    'object' => [
                        'type' => '%(*objecttype)',
                        'sha' => '%(*objectname)',
                        'message' => '%(*subject)',
                        'url' => '',
                    ],
                ];
                break;
            default:
                throw new \LogicException(sprintf('%s format not available', $key));
        }

        return json_encode($format);
    }

    /**
     * Take output string and convert it into array.
     *
     * @param string $name   Repository name
     * @param string $output Git command output
     *
     * @return array
     */
    private function parseCommit($name, $output)
    {
        return array_map(function ($str) use ($name) {
            if ($row = json_decode($str, true)) {
                $row['url'] = $this->generateUrl('get_commit', array(
                    'name' => $name,
                    'sha' => $row['sha'],
                ));
                $row['tree']['url'] = $this->generateUrl('get_tree', array(
                    'name' => $name,
                    'revision' => $row['tree']['sha'],
                ));
                if ($row['parent']['sha']) {
                    $row['parent']['url'] = $this->generateUrl('get_commit', array(
                        'name' => $name,
                        'sha' => $row['parent']['sha'],
                    ));
                }

                return $row;
            }
        }, $output);
    }

    /**
     * Check if revision exists in the repository.
     *
     * @param string $name     Repository name
     * @param string $revision Revision name
     *
     * @return bool
     */
    private function revisionExists($name, $revision)
    {
        // $object = substr($revision, 0, 2).'/'.substr($revision, 2);
        return file_exists($this->repoPath($name).'/refs/heads/'.$revision) // branch
            || file_exists($this->repoPath($name).'/refs/tags/'.$revision)  // tag
            || preg_match('/^[a-f0-9]{5,40}$/i', $revision)
            // || file_exists($this->repoPath($name).'/objects/'.$object)   // hash (sha)
            || ('HEAD' === $revision)                                       // HEAD
        ;
    }

    /**
     * Return the git version.
     *
     * @return string
     */
    public function getVersion()
    {
        $output = $this->run('--version');
        $row = explode(' ', $output);

        return rtrim($row[2], PHP_EOL);
    }

    /**
     * Generates an URL for a specific route.
     *
     * @param string $name      The name of the route
     * @param string $parameter An array of parameters
     *
     * @return string
     */
    public function generateUrl($name, array $parameter)
    {
        return $this->router->generate($name, $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Run git command.
     *
     * @param string $command The git command
     * @param string $name    Repository name
     *
     * @return string
     */
    public function run($command, $name = null)
    {
        $process = new Process(sprintf('git %s', $command),
            ($name) ? $this->repoPath($name) : null
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
