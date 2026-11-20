# Estrutura do Projeto

Árvore completa dos diretórios e arquivos versionados na branch `developer`, gerada a partir da árvore atual do GitHub.

Em cada nível, **as pastas aparecem primeiro, em ordem alfabética; depois aparecem os arquivos, também em ordem alfabética**. A regra é aplicada recursivamente.

- Branch: `developer`
- Total: 317 entradas (53 pastas e 264 arquivos).
- Referência da árvore antes desta atualização: `4c0b380b6f97a74a3f4582ff10395a7cded89e9f`

```text
├── .github/
│   └── workflows/
│       ├── ci.yml
│       ├── linkedin-posts-ci.yml
│       ├── linkedin-posts.yml
│       └── vacancies.yml
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── CheckNetwork.php
│   │       ├── CheckVacancyLinks.php
│   │       ├── CollectLinkedInPosts.php
│   │       ├── CollectVacancies.php
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
│   │       └── RejectLocationOverrides.php
│   ├── Jobs/
│   │   └── FetchVacancySourceJob.php
│   ├── Models/
│   │   ├── Job.php
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
│   │   ├── LinkedInPostCandidateProcessor.php
│   │   ├── LinkedInPostSearchClient.php
│   │   ├── LinkedInPublicPostFetcher.php
│   │   ├── LinkedInUrlInspector.php
│   │   ├── VacancyAvailabilityDetector.php
│   │   ├── VacancyImporter.php
│   │   ├── VacancyLinkChecker.php
│   │   ├── VacancyLocationFilter.php
│   │   └── VacancySourceFactory.php
│   └── Sources/
│       ├── AshbySource.php
│       ├── BizneoSource.php
│       ├── BradescoCsodSource.php
│       ├── BtgPactualSource.php
│       ├── CompleoSource.php
│       ├── CwiSource.php
│       ├── DerevoSource.php
│       ├── FactorialSource.php
│       ├── GeekHunterSource.php
│       ├── GreenhouseSource.php
│       ├── GupySource.php
│       ├── IbmSource.php
│       ├── InhireSource.php
│       ├── JobConvoSource.php
│       ├── JobylonSource.php
│       ├── LeverSource.php
│       ├── LevtySource.php
│       ├── MicrosoftSource.php
│       ├── MindsightSource.php
│       ├── OracleHcmSource.php
│       ├── OwnCareerPageSource.php
│       ├── OzySource.php
│       ├── PandapeSource.php
│       ├── PlooralSource.php
│       ├── QuickinSource.php
│       ├── RadarTecSource.php
│       ├── RecrutAiSource.php
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
│   │   └── 2026_11_15_000001_create_linkedin_post_candidates_table.php
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   └── VacancySeeder.php
│   └── .gitignore
├── docs/
│   ├── ArquiteturaEFluxoDeDados.md
│   ├── AtualizacoesRecentes.md
│   ├── AutomacaoETestes.md
│   ├── ColetaEIntegracaoDeVagas.md
│   ├── ColetaPublicacoesLinkedIn.md
│   ├── deteccao-de-redes-suspeitas.md
│   ├── EstruturaDoProjeto.md
│   ├── FontesGupyRecentes.md
│   ├── InstalacaoEConfiguracao.md
│   ├── LocalizacaoERegionalizacao.md
│   ├── RegrasDeElegibilidade.md
│   ├── SegurancaDeRedeELocalizacao.md
│   └── VisaoGeralDaAplicacao.md
├── public/
│   ├── .htaccess
│   ├── favicon.ico
│   ├── favicon.svg
│   ├── index.php
│   └── robots.txt
├── resources/
│   ├── css/
│   │   └── app.css
│   ├── js/
│   │   └── app.js
│   └── views/
│       ├── layouts/
│       │   └── app.blade.php
│       ├── network/
│       │   └── denied.blade.php
│       ├── partials/
│       │   └── location-refresh.blade.php
│       ├── vacancies/
│       │   ├── index.blade.php
│       │   └── show.blade.php
│       └── welcome.blade.php
├── routes/
│   ├── console.php
│   └── web.php
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
│   │   ├── AbuseIpDbDetectorTest.php
│   │   ├── AshbySourceTest.php
│   │   ├── BizneoSourceTest.php
│   │   ├── BradescoCsodSourceTest.php
│   │   ├── BtgPactualSourceTest.php
│   │   ├── CompleoSourceTest.php
│   │   ├── CwiSourceTest.php
│   │   ├── DerevoSourceTest.php
│   │   ├── ExampleTest.php
│   │   ├── FactorialSourceTest.php
│   │   ├── GeekHunterSourceTest.php
│   │   ├── GreenhouseSourceTest.php
│   │   ├── GupySourceTest.php
│   │   ├── IbmSourceTest.php
│   │   ├── InhireSourceTest.php
│   │   ├── IpListStoreTest.php
│   │   ├── JobConvoSourceTest.php
│   │   ├── JobylonSourceTest.php
│   │   ├── LeverSourceTest.php
│   │   ├── LevtySourceTest.php
│   │   ├── LinkedInPostCandidateProcessorTest.php
│   │   ├── LinkedInPostSearchClientTest.php
│   │   ├── LocationRefreshTest.php
│   │   ├── LocationSpoofingProtectionTest.php
│   │   ├── MicrosoftSourceTest.php
│   │   ├── MindsightSourceTest.php
│   │   ├── NetworkListsCommandTest.php
│   │   ├── NetworkReputationMiddlewareTest.php
│   │   ├── OracleHcmSourceTest.php
│   │   ├── OwnCareerPageSourceTest.php
│   │   ├── OzySourceTest.php
│   │   ├── PandapeSourceTest.php
│   │   ├── PlooralSourceTest.php
│   │   ├── QuickinSourceTest.php
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
│   │   ├── VacancyImporterTest.php
│   │   ├── VacancyLinkCheckerTest.php
│   │   ├── VagasSourceTest.php
│   │   ├── WelcomeAcceptanceTest.php
│   │   ├── WorkableSourceTest.php
│   │   ├── WorkdaySourceTest.php
│   │   └── ZohoRecruitSourceTest.php
│   ├── Unit/
│   │   ├── ExampleTest.php
│   │   ├── GeoLocationNormalizerTest.php
│   │   ├── IpRangeSetTest.php
│   │   ├── ListParserTest.php
│   │   ├── LocationProofTest.php
│   │   ├── LocationSpoofingDetectorTest.php
│   │   ├── NetworkPolicyTest.php
│   │   ├── VacancyAvailabilityDetectorCoverageTest.php
│   │   ├── VacancyAvailabilityDetectorTest.php
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
├── package.json
├── phpunit.xml
├── README.md
├── render.yaml
└── vite.config.js
```

> Observação: diretórios de dependências e arquivos gerados ignorados pelo Git não aparecem nesta árvore. O arquivo `docs/EstruturaDoProjeto.md` está incluído.
