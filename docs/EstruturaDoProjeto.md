# Estrutura do Projeto

Árvore dos diretórios e arquivos versionados na branch `developer`, acrescida dos documentos criados nesta branch. Os nomes são apresentados como estão registrados no repositório e os itens de cada diretório estão em ordem alfabética.

```text
├── .editorconfig
├── .env.example
├── .gitattributes
├── .github/
│   └── workflows/
│       ├── ci.yml
│       └── vacancies.yml
├── .gitignore
├── .npmrc
├── AGENTS.md
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── CheckNetwork.php
│   │       ├── CollectVacancies.php
│   │       ├── ImportIbgeGeography.php
│   │       ├── PruneExpiredVacancies.php
│   │       ├── ResolveVacancyMunicipalities.php
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
│   │       ├── EnsureWelcomeAccepted.php
│   │       ├── EvaluateNetworkReputation.php
│   │       └── RejectLocationOverrides.php
│   ├── Jobs/
│   │   └── FetchVacancySourceJob.php
│   ├── Models/
│   │   ├── Job.php
│   │   ├── Municipality.php
│   │   ├── User.php
│   │   └── Vacancy.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   ├── Services/
│   │   ├── Geo/
│   │   │   ├── IbgeGeographyImporter.php
│   │   │   ├── IpLocationResolver.php
│   │   │   ├── MunicipalityDirectory.php
│   │   │   ├── RegionScope.php
│   │   │   ├── Security/
│   │   │   │   ├── LocationAssessment.php
│   │   │   │   ├── LocationGuard.php
│   │   │   │   ├── LocationProof.php
│   │   │   │   ├── LocationSample.php
│   │   │   │   └── LocationSpoofingDetector.php
│   │   │   ├── VacancyLocationResolver.php
│   │   │   └── VisitorLocationResolver.php
│   │   ├── GeoLocationNormalizer.php
│   │   ├── Network/
│   │   │   ├── AbuseTracker.php
│   │   │   ├── Detectors/
│   │   │   │   ├── AbuseIpDbDetector.php
│   │   │   │   └── ListDetector.php
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
│   │   ├── VacancyAvailabilityDetector.php
│   │   ├── VacancyImporter.php
│   │   ├── VacancyLocationFilter.php
│   │   └── VacancySourceFactory.php
│   └── Sources/
│       ├── AshbySource.php
│       ├── DerevoSource.php
│       ├── GeekHunterSource.php
│       ├── GreenhouseSource.php
│       ├── GupySource.php
│       ├── InhireSource.php
│       ├── JobConvoSource.php
│       ├── LeverSource.php
│       ├── OracleHcmSource.php
│       ├── OzySource.php
│       ├── PandapeSource.php
│       ├── RadarTecSource.php
│       ├── RecrutAiSource.php
│       ├── SensediaSource.php
│       ├── TotvsAtsSource.php
│       ├── WorkableSource.php
│       ├── WorkdaySource.php
│       └── ZohoRecruitSource.php
├── artisan
├── bootstrap/
│   ├── app.php
│   ├── cache/
│   │   └── .gitignore
│   └── providers.php
├── CLAUDE.md
├── composer.json
├── composer.lock
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── cache.php
│   ├── database.php
│   ├── filesystems.php
│   ├── geo_regions.php
│   ├── location_guard.php
│   ├── logging.php
│   ├── mail.php
│   ├── network_security.php
│   ├── queue.php
│   ├── services.php
│   ├── session.php
│   └── vacancy_sources.php
├── database/
│   ├── .gitignore
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
│   │   └── 2026_11_10_000002_create_geography_import_status_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── VacancySeeder.php
├── Dockerfile
├── docs/
│   ├── ArquiteturaEFluxoDeDados.md
│   ├── AutomacaoETestes.md
│   ├── ColetaEIntegracaoDeVagas.md
│   ├── deteccao-de-redes-suspeitas.md
│   ├── EstruturaDoProjeto.md
│   ├── InstalacaoEConfiguracao.md
│   ├── LocalizacaoERegionalizacao.md
│   ├── RegrasDeElegibilidade.md
│   ├── SegurancaDeRedeELocalizacao.md
│   └── VisaoGeralDaAplicacao.md
├── package.json
├── phpunit.xml
├── public/
│   ├── .htaccess
│   ├── favicon.ico
│   ├── index.php
│   └── robots.txt
├── render.yaml
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
│   │   ├── .gitignore
│   │   ├── private/
│   │   │   └── .gitignore
│   │   └── public/
│   │       └── .gitignore
│   ├── framework/
│   │   ├── .gitignore
│   │   ├── cache/
│   │   │   ├── .gitignore
│   │   │   ├── data/
│   │   │   │   └── .gitignore
│   │   │   └── locks/
│   │   │       └── .gitignore
│   │   ├── sessions/
│   │   │   └── .gitignore
│   │   ├── testing/
│   │   │   └── .gitignore
│   │   └── views/
│   │       └── .gitignore
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
│   │   ├── DerevoSourceTest.php
│   │   ├── ExampleTest.php
│   │   ├── GeekHunterSourceTest.php
│   │   ├── GreenhouseSourceTest.php
│   │   ├── GupySourceTest.php
│   │   ├── InhireSourceTest.php
│   │   ├── IpListStoreTest.php
│   │   ├── JobConvoSourceTest.php
│   │   ├── LeverSourceTest.php
│   │   ├── LocationRefreshTest.php
│   │   ├── LocationSpoofingProtectionTest.php
│   │   ├── NetworkListsCommandTest.php
│   │   ├── NetworkReputationMiddlewareTest.php
│   │   ├── OracleHcmSourceTest.php
│   │   ├── OzySourceTest.php
│   │   ├── PandapeSourceTest.php
│   │   ├── RadarTecSourceTest.php
│   │   ├── RateLimitingTest.php
│   │   ├── RecrutAiSourceTest.php
│   │   ├── RegionalizationTest.php
│   │   ├── SensediaSourceTest.php
│   │   ├── TotvsAtsSourceTest.php
│   │   ├── VacancyControllerTest.php
│   │   ├── VacancyImporterTest.php
│   │   ├── WelcomeAcceptanceTest.php
│   │   ├── WorkableSourceTest.php
│   │   ├── WorkdaySourceTest.php
│   │   └── ZohoRecruitSourceTest.php
│   ├── TestCase.php
│   └── Unit/
│       ├── ExampleTest.php
│       ├── GeoLocationNormalizerTest.php
│       ├── IpRangeSetTest.php
│       ├── ListParserTest.php
│       ├── LocationProofTest.php
│       ├── LocationSpoofingDetectorTest.php
│       ├── NetworkPolicyTest.php
│       ├── VacancyAvailabilityDetectorCoverageTest.php
│       ├── VacancyAvailabilityDetectorTest.php
│       └── VacancyLocationFilterTest.php
└── vite.config.js
```

> Observação: diretórios de dependências e arquivos gerados ignorados pelo Git não aparecem nesta árvore. O arquivo `docs/EstruturaDoProjeto.md` está incluído na própria estrutura.
