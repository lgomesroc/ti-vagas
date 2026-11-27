# Estrutura do Projeto

Árvore de diretórios e arquivos versionados na branch `main`, gerada a partir do índice Git (`git ls-files`) com os ajustes do PWA aplicados sobre o commit `dc4c9526216df1092b0ad05726996b3c3d8cda96`.

**Ordenação aplicada recursivamente em cada pasta:**
1. Diretórios primeiro, em ordem alfabética;
2. Em seguida, os arquivos, em ordem alfabética.

A ordem alfabética não diferencia maiúsculas de minúsculas; nomes iniciados por ponto (como `.github/` e `.gitignore`) aparecem antes das letras.

- Branch: `main`
- Commit base: `dc4c9526216df1092b0ad05726996b3c3d8cda96`
- Diretórios: 63
- Arquivos: 382

```text
├── .github/
│   └── workflows/
│       ├── abler.yml
│       ├── ci.yml
│       ├── database-failover.yml
│       ├── gupy.yml
│       ├── inhire.yml
│       ├── linkedin-jobs-basis.yml
│       ├── linkedin-jobs-brq.yml
│       ├── linkedin-jobs-cast.yml
│       ├── linkedin-jobs-gft.yml
│       ├── linkedin-jobs.yml
│       ├── linkedin-posts-ci.yml
│       ├── linkedin-posts.yml
│       ├── linkedin-secondary-search.yml
│       ├── linkedin-session-monitor.yml
│       └── vacancies.yml
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── CheckNetwork.php
│   │       ├── CheckVacancyLinks.php
│   │       ├── CollectGupyVacancies.php
│   │       ├── CollectInhireVacancies.php
│   │       ├── CollectLinkedInJobs.php
│   │       ├── CollectLinkedInPosts.php
│   │       ├── CollectLinkedInSecondary.php
│   │       ├── CollectSingleVacancySource.php
│   │       ├── CollectVacancies.php
│   │       ├── DiagnoseLinkedInSearch.php
│   │       ├── ImportIbgeGeography.php
│   │       ├── PruneExpiredVacancies.php
│   │       ├── ResolveVacancyMunicipalities.php
│   │       ├── ReviewLinkedInPosts.php
│   │       ├── UpdateLinkedInPostCandidate.php
│   │       ├── UpdateNetworkLists.php
│   │       └── VacancyQueueSize.php
│   ├── Contracts/
│   │   ├── NetworkDetector.php
│   │   └── VacancySource.php
│   ├── Exceptions/
│   │   └── SourceRateLimitedException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php
│   │   │   ├── LocationRefreshController.php
│   │   │   ├── VacancyController.php
│   │   │   └── WelcomeController.php
│   │   └── Middleware/
│   │       ├── CaptureUtmParameters.php
│   │       ├── EnsureWelcomeAccepted.php
│   │       ├── EvaluateNetworkReputation.php
│   │       ├── ForceHttps.php
│   │       └── RejectLocationOverrides.php
│   ├── Jobs/
│   │   ├── FetchLinkedInJobsJob.php
│   │   └── FetchVacancySourceJob.php
│   ├── Models/
│   │   ├── Job.php
│   │   ├── LinkedInJobScan.php
│   │   ├── LinkedInPostCandidate.php
│   │   ├── Municipality.php
│   │   ├── User.php
│   │   ├── Vacancy.php
│   │   └── VacancyAvailabilityCheck.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   ├── Services/
│   │   ├── Geo/
│   │   │   ├── Security/
│   │   │   │   ├── LocationAssessment.php
│   │   │   │   ├── LocationGuard.php
│   │   │   │   ├── LocationProof.php
│   │   │   │   ├── LocationSample.php
│   │   │   │   └── LocationSpoofingDetector.php
│   │   │   ├── IbgeGeographyImporter.php
│   │   │   ├── IpLocationResolver.php
│   │   │   ├── MunicipalityDirectory.php
│   │   │   ├── RegionScope.php
│   │   │   ├── VacancyLocationResolver.php
│   │   │   └── VisitorLocationResolver.php
│   │   ├── Network/
│   │   │   ├── Detectors/
│   │   │   │   ├── AbuseIpDbDetector.php
│   │   │   │   └── ListDetector.php
│   │   │   ├── AbuseTracker.php
│   │   │   ├── IpFingerprint.php
│   │   │   ├── IpListStore.php
│   │   │   ├── IpRangeSet.php
│   │   │   ├── ListParser.php
│   │   │   ├── NetworkAction.php
│   │   │   ├── NetworkAssessment.php
│   │   │   ├── NetworkCategory.php
│   │   │   ├── NetworkClassifier.php
│   │   │   ├── NetworkPolicy.php
│   │   │   ├── NetworkSignal.php
│   │   │   └── SignalConfidence.php
│   │   ├── GeoLocationNormalizer.php
│   │   ├── LinkedInPostAutoPublisher.php
│   │   ├── LinkedInPostCandidateProcessor.php
│   │   ├── LinkedInPostSearchClient.php
│   │   ├── LinkedInPublicPostFetcher.php
│   │   ├── LinkedInUrlInspector.php
│   │   ├── VacancyAvailabilityDetector.php
│   │   ├── VacancyDuplicateDetector.php
│   │   ├── VacancyEditorialFilter.php
│   │   ├── VacancyImporter.php
│   │   ├── VacancyLinkChecker.php
│   │   ├── VacancyLocationFilter.php
│   │   └── VacancySourceFactory.php
│   └── Sources/
│       ├── AblerSource.php
│       ├── AshbySource.php
│       ├── BizneoSource.php
│       ├── BneSource.php
│       ├── BradescoCsodSource.php
│       ├── BtgPactualSource.php
│       ├── CompleoSource.php
│       ├── CwiSource.php
│       ├── DerevoSource.php
│       ├── FactorialSource.php
│       ├── GeekHunterSource.php
│       ├── GreenhouseSource.php
│       ├── GupyGlobalSource.php
│       ├── GupySource.php
│       ├── IbmSource.php
│       ├── InhireGlobalSource.php
│       ├── InhireSource.php
│       ├── JobConvoSource.php
│       ├── JobylonSource.php
│       ├── LeverSource.php
│       ├── LevtySource.php
│       ├── LinkedInJobsSource.php
│       ├── MicrosoftSource.php
│       ├── MindsightSource.php
│       ├── NerdinSource.php
│       ├── NetVagasSource.php
│       ├── NortJobsSource.php
│       ├── OracleHcmSource.php
│       ├── OwnCareerPageSource.php
│       ├── OzySource.php
│       ├── PandapeSource.php
│       ├── PlooralSource.php
│       ├── ProgramathorSource.php
│       ├── QuickinSource.php
│       ├── RadancySource.php
│       ├── RadarTecSource.php
│       ├── RecrutAiSource.php
│       ├── RioVagasSource.php
│       ├── RjEmpregosSource.php
│       ├── SensediaSource.php
│       ├── SmartRecruitersSource.php
│       ├── SolidesSource.php
│       ├── SuccessFactorsSource.php
│       ├── TalentbrandSource.php
│       ├── TeamtailorSource.php
│       ├── TotvsAtsSource.php
│       ├── VagasSource.php
│       ├── WorkableSource.php
│       ├── WorkdaySource.php
│       └── ZohoRecruitSource.php
├── bootstrap/
│   ├── cache/
│   │   └── .gitignore
│   ├── app.php
│   └── providers.php
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── cache.php
│   ├── database.php
│   ├── filesystems.php
│   ├── geo_regions.php
│   ├── linkedin.php
│   ├── location_guard.php
│   ├── logging.php
│   ├── mail.php
│   ├── network_security.php
│   ├── nort.php
│   ├── queue.php
│   ├── services.php
│   ├── session.php
│   └── vacancy_sources.php
├── database/
│   ├── data/
│   │   └── municipios.csv
│   ├── factories/
│   │   └── UserFactory.php
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2026_11_03_144228_create_jobs_table.php
│   │   ├── 2026_11_08_000001_add_geographic_fields_to_vacancies.php
│   │   ├── 2026_11_10_000001_create_municipalities_table.php
│   │   ├── 2026_11_10_000002_create_geography_import_status_table.php
│   │   ├── 2026_11_14_220000_add_vacancy_availability_monitoring.php
│   │   ├── 2026_11_15_000001_create_linkedin_post_candidates_table.php
│   │   ├── 2026_11_19_000001_expand_municipality_region_codes.php
│   │   ├── 2026_11_19_000001_move_linkedin_candidates_to_automatic_publication.php
│   │   ├── 2026_11_22_110000_create_linkedin_job_scans_table.php
│   │   ├── 2026_11_24_160000_add_email_application_channels_to_vacancies.php
│   │   └── 2026_11_27_120000_add_source_classification_to_vacancies_table.php
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   └── VacancySeeder.php
│   └── .gitignore
├── docs/
│   ├── AplicativoPWA.md
│   ├── ArquiteturaEFluxoDeDados.md
│   ├── AtualizacoesRecentes.md
│   ├── AutomacaoETestes.md
│   ├── ColetaEIntegracaoDeVagas.md
│   ├── ColetaPublicacoesLinkedIn.md
│   ├── ConsentimentoEAtribuicao.md
│   ├── deteccao-de-redes-suspeitas.md
│   ├── EstruturaDoProjeto.md
│   ├── ExpiracaoDeAnuncios.md
│   ├── FontesGupyRecentes.md
│   ├── FontesIBMEMicrosoft.md
│   ├── FuncionalidadesDoPortal.md
│   ├── InstalacaoEConfiguracao.md
│   ├── IntegracaoAbler.md
│   ├── LocalizacaoERegionalizacao.md
│   ├── PaginasDeErroPadronizadas.md
│   ├── RegrasDeElegibilidade.md
│   ├── ResponsabilidadeELimitacoes.md
│   ├── SegurancaDeRedeELocalizacao.md
│   ├── TecnologiasUtilizadas.md
│   ├── VerificacaoDeDisponibilidade.md
│   ├── VisaoGeralDaAplicacao.md
│   └── WorkflowsGitHubActions.md
├── ibge/
│   ├── extraido/
│   │   ├── DISTRITOS-SUBDISTRITOS_NOVOS_E_EXTINTOS_2025.ods
│   │   ├── DISTRITOS-SUBDISTRITOS_NOVOS_E_EXTINTOS_2025.xls
│   │   ├── RELATORIO_DTB_BRASIL_2025_DISTRITOS.ods
│   │   ├── RELATORIO_DTB_BRASIL_2025_DISTRITOS.xls
│   │   ├── RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.ods
│   │   ├── RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.xls
│   │   ├── RELATORIO_DTB_BRASIL_2025_SUBDISTRITOS.ods
│   │   └── RELATORIO_DTB_BRASIL_2025_SUBDISTRITOS.xls
│   └── DTB_2025.zip
├── public/
│   ├── icons/
│   │   ├── apple-touch-icon.png
│   │   ├── icon-192.png
│   │   ├── icon-512.png
│   │   ├── icon-maskable-192.png
│   │   ├── icon-maskable-512.png
│   │   └── icon-maskable.svg
│   ├── .htaccess
│   ├── favicon.ico
│   ├── favicon.svg
│   ├── index.php
│   ├── manifest.webmanifest
│   ├── offline.html
│   ├── robots.txt
│   └── sw.js
├── resources/
│   ├── css/
│   │   └── app.css
│   ├── js/
│   │   └── app.js
│   └── views/
│       ├── errors/
│       │   ├── 400.blade.php
│       │   ├── 404.blade.php
│       │   ├── 405.blade.php
│       │   ├── 419.blade.php
│       │   ├── 429.blade.php
│       │   ├── 4xx.blade.php
│       │   ├── 500.blade.php
│       │   ├── 503.blade.php
│       │   ├── 5xx.blade.php
│       │   └── layout.blade.php
│       ├── layouts/
│       │   └── app.blade.php
│       ├── network/
│       │   └── denied.blade.php
│       ├── partials/
│       │   ├── location-refresh.blade.php
│       │   ├── pwa-head.blade.php
│       │   └── pwa-register.blade.php
│       ├── vacancies/
│       │   ├── index.blade.php
│       │   └── show.blade.php
│       └── welcome.blade.php
├── routes/
│   ├── console.php
│   └── web.php
├── scripts/
│   ├── abler/
│   │   └── collect.mjs
│   ├── ibge/
│   │   └── import-dtb-2025.ps1
│   └── linkedin/
│       ├── export-session.mjs
│       ├── export-session.test.mjs
│       ├── jobs.e2e.test.mjs
│       ├── jobs.mjs
│       ├── rate-limit.mjs
│       ├── rate-limit.test.mjs
│       ├── restore-session.mjs
│       ├── restore-session.test.mjs
│       ├── runtime.mjs
│       ├── runtime.test.mjs
│       ├── secondary-account.e2e.test.mjs
│       ├── secondary-account.mjs
│       ├── session-check.mjs
│       └── session-check.test.mjs
├── storage/
│   ├── app/
│   │   ├── private/
│   │   │   └── .gitignore
│   │   ├── public/
│   │   │   └── .gitignore
│   │   └── .gitignore
│   ├── framework/
│   │   ├── cache/
│   │   │   ├── data/
│   │   │   │   └── .gitignore
│   │   │   ├── locks/
│   │   │   │   └── .gitignore
│   │   │   └── .gitignore
│   │   ├── sessions/
│   │   │   └── .gitignore
│   │   ├── testing/
│   │   │   └── .gitignore
│   │   ├── views/
│   │   │   └── .gitignore
│   │   └── .gitignore
│   └── logs/
│       └── .gitignore
├── tests/
│   ├── Concerns/
│   │   ├── BuildsVerifiedLocationSession.php
│   │   ├── ConfiguresNetworkSecurity.php
│   │   └── SeedsGeography.php
│   ├── Feature/
│   │   ├── AblerSourceTest.php
│   │   ├── AbuseIpDbDetectorTest.php
│   │   ├── AshbySourceTest.php
│   │   ├── BizneoSourceTest.php
│   │   ├── BradescoCsodSourceTest.php
│   │   ├── BtgPactualSourceTest.php
│   │   ├── CollectLinkedInPostsTest.php
│   │   ├── CompleoSourceTest.php
│   │   ├── CwiSourceTest.php
│   │   ├── DerevoSourceTest.php
│   │   ├── ErrorPagesTest.php
│   │   ├── ExampleTest.php
│   │   ├── FactorialSourceTest.php
│   │   ├── ForceHttpsTest.php
│   │   ├── GeekHunterSourceTest.php
│   │   ├── GreenhouseSourceTest.php
│   │   ├── GupyGlobalSourceTest.php
│   │   ├── GupySourceTest.php
│   │   ├── IbmSourceTest.php
│   │   ├── InhireSourceTest.php
│   │   ├── IpListStoreTest.php
│   │   ├── JobConvoSourceTest.php
│   │   ├── JobylonSourceTest.php
│   │   ├── LeverSourceTest.php
│   │   ├── LevtySourceTest.php
│   │   ├── LinkedInJobsRateLimitTest.php
│   │   ├── LinkedInJobsSourceTest.php
│   │   ├── LinkedInPostCandidateProcessorTest.php
│   │   ├── LinkedInPostEmailApplicationTest.php
│   │   ├── LinkedInPostSearchClientTest.php
│   │   ├── LinkedInRuntimeConfigTest.php
│   │   ├── LocationPermissionFlowTest.php
│   │   ├── LocationRefreshTest.php
│   │   ├── LocationSpoofingProtectionTest.php
│   │   ├── MicrosoftSourceTest.php
│   │   ├── MindsightSourceTest.php
│   │   ├── NetVagasSourceTest.php
│   │   ├── NetworkListsCommandTest.php
│   │   ├── NetworkReputationMiddlewareTest.php
│   │   ├── OracleHcmSourceTest.php
│   │   ├── OwnCareerPageSourceTest.php
│   │   ├── OzySourceTest.php
│   │   ├── PandapeSourceTest.php
│   │   ├── PlooralSourceTest.php
│   │   ├── ProgramathorSourceTest.php
│   │   ├── PwaTest.php
│   │   ├── QuickinSourceTest.php
│   │   ├── RadancySourceTest.php
│   │   ├── RadarTecSourceTest.php
│   │   ├── RateLimitingTest.php
│   │   ├── RecrutAiSourceTest.php
│   │   ├── RegionalizationTest.php
│   │   ├── RjEmpregosSourceTest.php
│   │   ├── SensediaSourceTest.php
│   │   ├── SmartRecruitersSourceTest.php
│   │   ├── SolidesSourceTest.php
│   │   ├── SuccessFactorsSourceTest.php
│   │   ├── TalentbrandSourceTest.php
│   │   ├── TeamtailorSourceTest.php
│   │   ├── TotvsAtsSourceTest.php
│   │   ├── UtmAttributionTest.php
│   │   ├── VacancyControllerTest.php
│   │   ├── VacancyEmailApplicationTest.php
│   │   ├── VacancyImporterTest.php
│   │   ├── VacancyLinkCheckerTest.php
│   │   ├── VagasSourceTest.php
│   │   ├── WelcomeAcceptanceTest.php
│   │   ├── WorkableSourceTest.php
│   │   ├── WorkdaySourceTest.php
│   │   └── ZohoRecruitSourceTest.php
│   ├── pwa/
│   │   └── service-worker.e2e.test.mjs
│   ├── Unit/
│   │   ├── ExampleTest.php
│   │   ├── GeoLocationNormalizerTest.php
│   │   ├── IpRangeSetTest.php
│   │   ├── ListParserTest.php
│   │   ├── LocationProofTest.php
│   │   ├── LocationSpoofingDetectorTest.php
│   │   ├── NetworkPolicyTest.php
│   │   ├── PandapeSourceFilterTest.php
│   │   ├── VacancyAvailabilityDetectorCoverageTest.php
│   │   ├── VacancyAvailabilityDetectorTest.php
│   │   ├── VacancyDuplicateDetectorTest.php
│   │   ├── VacancyEditorialFilterTest.php
│   │   └── VacancyLocationFilterTest.php
│   └── TestCase.php
├── .editorconfig
├── .env.example
├── .env.testing.example
├── .gitattributes
├── .gitignore
├── .npmrc
├── AGENTS.md
├── artisan
├── CLAUDE.md
├── composer.json
├── composer.lock
├── Dockerfile
├── package-lock.json
├── package.json
├── phpunit.xml
├── README.md
├── render.yaml
└── vite.config.js
```

Para regenerar a árvore, liste os arquivos versionados com `git ls-files` e aplique a mesma ordenação (diretórios antes de arquivos, ambos em ordem alfabética, em todos os níveis).
