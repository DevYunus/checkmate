<?php

namespace App\Console\Commands;

use App\Project;
use Github\Client as GitHubClient;
use Github\ResultPager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncProjects extends Command
{
    protected $signature = 'sync:projects';

    protected $description = 'Sync projects from Tighten\'s Github organization';

    protected $client;

    public function __construct(GitHubClient $client)
    {
        parent::__construct();

        $this->client = $client;
    }

    public function handle()
    {
        $projects = Project::all();
        $gitHubRepos = $this->fetchRepos();

        $gitHubRepos->reject(function ($repo, $key) use ($projects) {
            $project = $projects->first(function ($project, $key) use ($repo) {
                return strtolower($project->name) == strtolower($repo['name']);
            });

            return ! is_null($project) || $repo['language'] !== 'PHP' || $repo['fork'];
        })->map(function ($repo) {
            list($vendor, $package) = explode("/", $repo['full_name']);

            Project::create([
                'name' => $repo['name'],
                'vendor' => $vendor,
                'package' => $package,
                'ignored' => false,
            ]);
        });

        $this->info("Finished Syncing {$gitHubRepos->count()} repos");
    }

    protected function fetchRepos()
    {
        return Cache::remember('repos', DAY_IN_SECONDS, function () {
            $githubClient = app(GitHubClient::class);

            $repos = (new ResultPager($githubClient))->fetchAll(
                $githubClient->api('organization'),
                'repositories',
                ['tightenco']
            );

            return collect($repos);
        });
    }
}
