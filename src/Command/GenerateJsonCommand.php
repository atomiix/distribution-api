<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Module;
use App\Util\ModuleUtils;
use Github\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateJsonCommand extends Command
{
    protected static $defaultName = 'generateJson';

    private ModuleUtils $moduleUtils;
    private Client $githubClient;
    private string $jsonDir;

    public function __construct(
        ModuleUtils $moduleUtils,
        Client $client,
        string $jsonDir = __DIR__ . '/../../public/json'
    ) {
        parent::__construct();
        $this->moduleUtils = $moduleUtils;
        $this->githubClient = $client;
        $this->jsonDir = $jsonDir;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $modules = $this->moduleUtils->getLocalModules();
        foreach ($modules as $module) {
            foreach ($module->getVersions() as $version) {
                $output->writeln(sprintf('<info>Parsing module %s %s</info>', $module->getName(), $version->getTag()));
                $this->moduleUtils->setVersionCompliancy($module->getName(), $version);
            }
        }

        $prestashopVersions = $this->getPrestaShopVersions();
        if (empty($modules) || empty($prestashopVersions)) {
            $output->writeln('<error>No module or PrestaShop version found!</error>');
            $output->writeln('<question>Did you run the `downloadNativeModule` command?</question>');

            return static::FAILURE;
        }

        $output->writeln('<info>Generating main modules.json</info>');
        $this->generatePrestaShopModulesJson($modules, $prestashopVersions, $output);

        return static::SUCCESS;
    }

    /**
     * @return array<string>
     */
    protected function getPrestaShopVersions(): array
    {
        $page = 1;
        $versions = [];
        $releasesApi = $this->githubClient->repo()->releases();
        while (count($results = $releasesApi->all('PrestaShop', 'PrestaShop', ['page' => $page++])) > 0) {
            $versions = array_merge($versions, array_filter($results, fn ($item) => !empty($item['assets'])));
        }

        return array_map(fn ($item) => $item['tag_name'], $versions);
    }

    /**
     * @param Module[] $modules
     * @param non-empty-array<string> $prestashopVersions
     */
    private function generatePrestaShopModulesJson(
        array $modules,
        array $prestashopVersions,
        OutputInterface $output
    ): void {
        $infos = [];
        foreach ($prestashopVersions as $prestashopVersion) {
            $infos[$prestashopVersion] = [];
            foreach ($modules as $module) {
                foreach ($module->getVersions() as $version) {
                    if (null === $version->getVersionCompliancyMin() || null === $version->getVersion()) {
                        continue;
                    }
                    if (
                        version_compare($prestashopVersion, $version->getVersionCompliancyMin(), '>')
                        && (
                            empty($infos[$prestashopVersion][$module->getName()])
                            || version_compare($version->getVersion(), $infos[$prestashopVersion][$module->getName()]->getVersion(), '>')
                        )
                    ) {
                        $infos[$prestashopVersion][$module->getName()] = $version;
                    }
                }
            }
        }

        foreach ($infos as $prestashopVersion => $modules) {
            $output->writeln(sprintf('<info>Generate json for PrestaShop %s</info>', $prestashopVersion));
            $prestashopVersionPath = $this->jsonDir . '/' . $prestashopVersion;
            if (!is_dir($prestashopVersionPath)) {
                mkdir($prestashopVersionPath);
            }
            file_put_contents($prestashopVersionPath . '/modules.json', json_encode($modules));
        }
    }
}
