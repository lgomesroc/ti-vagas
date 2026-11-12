
@extends('layouts.app')

@section('title', 'Boas-vindas | TI Vagas')

@section('description', 'Conheça o TI Vagas, leia os termos e configure sua experiência de navegação.')

@section('content')

<main class="welcome-page">
    <div class="container">

        <section class="welcome-hero">
            <span class="welcome-eyebrow">SEJA BEM-VINDO</span>

            <h1>Sua próxima oportunidade em tecnologia começa aqui.</h1>

            <p>
                O TI Vagas reúne oportunidades de desenvolvimento,
                suporte técnico e infraestrutura para ajudar você
                a encontrar novos caminhos profissionais.
            </p>
        </section>

        <section class="welcome-card">

            <h2>Antes de continuar</h2>

            <p>
                Queremos explicar como o TI Vagas funciona e quais
                recursos utilizam informações da sua localização.
            </p>

            <div class="welcome-information">
                <h3>Localização</h3>

                <p>
                    A localização permite identificar oportunidades
                    próximas e oferecer recursos regionais.
                </p>

                <p>
                    Ao autorizar o acesso à localização pelo navegador,
                    você terá acesso à experiência completa dos recursos
                    que dependem dessa informação.
                </p>

                <p>
                    As vagas remotas nacionais poderão ser exibidas
                    independentemente da localização. Vagas presenciais,
                    híbridas ou sem modalidade identificada serão
                    exibidas apenas para a sua região: a região metropolitana
                    (ou RIDE) do seu município ou, fora delas, a região
                    geográfica intermediária do IBGE.
                </p>

                <p>
                    Se não autorizar ou se a localização estiver indisponível,
                    o TI Vagas poderá consultar seu endereço IP em um serviço
                    externo para estimar cidade e estado. Essa estimativa é
                    aproximada, não substitui a autorização do navegador e
                    não libera vagas presenciais ou híbridas. Você verá somente
                    vagas remotas nacionais até ativar a localização.
                </p>

                <p>
                    O TI Vagas não oferece uma lista de cidades para substituir
                    a autorização de localização nesta versão.
                </p>
            </div>

            <div class="welcome-information">
                <h3>Publicidade</h3>

                <p>
                    O TI Vagas é gratuito e poderá exibir anúncios relacionados
                    ao universo profissional e tecnológico, como cursos,
                    formação, idiomas, currículos, serviços financeiros
                    e ferramentas de trabalho.
                </p>

                <p>
                    Não exibiremos publicidade de apostas e bets ou bebidas
                    alcoólicas. Os anúncios serão identificados como publicidade
                    e distribuídos ao longo da navegação.
                </p>
            </div>

            <form
                id="welcome-form"
                method="POST"
                action="{{ route('welcome.accept') }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="location_status"
                    id="location-status"
                    value="{{ old('location_status', 'limited') }}"
                >

                <input
                    type="hidden"
                    name="latitude"
                    id="latitude"
                    value="{{ old('latitude') }}"
                >

                <input
                    type="hidden"
                    name="longitude"
                    id="longitude"
                    value="{{ old('longitude') }}"
                >

                <input
                    type="hidden"
                    name="state_code"
                    id="state-code"
                    value="{{ old('state_code') }}"
                >

                <input
                    type="hidden"
                    name="city_name"
                    id="city-name"
                    value="{{ old('city_name') }}"
                >

                <div class="terms-acceptance">
                    @if ($termsAccepted)
                        <input type="hidden" name="terms" value="1">
                    @endif

                    <label>
                        <input
                            type="checkbox"
                            id="terms-accepted"
                            name="terms"
                            value="1"
                            required
                            @checked($termsAccepted || old('terms'))
                            @disabled($termsAccepted)
                        >

                        <span>
                            Li e aceito os
                            <a href="#termos">Termos de Uso</a>
                            e os
                            <a href="#responsabilidade">Termos de Responsabilidade</a>.
                        </span>
                    </label>

                    @error('terms')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="location-actions">

                    <button
                        type="button"
                        id="enable-location"
                        class="button welcome-primary-button"
                        disabled
                    >
                        Ativar localização e continuar
                    </button>

                    <button
                        type="submit"
                        id="continue-limited"
                        class="button welcome-secondary-button"
                        disabled
                    >
                        Continuar com experiência limitada
                    </button>

                </div>

                <p
                    id="location-message"
                    class="location-message"
                    role="status"
                    aria-live="polite"
                >
                    Marque a opção de aceite dos termos para habilitar
                    os botões e continuar.
                </p>

                @error('location_status')
                    <p class="form-error">{{ $message }}</p>
                @enderror

                @error('latitude')
                    <p class="form-error">{{ $message }}</p>
                @enderror

                @error('longitude')
                    <p class="form-error">{{ $message }}</p>
                @enderror

                @error('state_code')
                    <p class="form-error">{{ $message }}</p>
                @enderror

                @error('city_name')
                    <p class="form-error">{{ $message }}</p>
                @enderror

            </form>

        </section>

        <section class="legal-section" id="termos">
            <h2>Termos de Uso</h2>

            <p>
                O TI Vagas é um portal gratuito de divulgação e agregação
                de oportunidades profissionais na área de tecnologia.
                O portal reúne informações de vagas publicadas por empresas
                e plataformas externas.
            </p>

            <p>
                As informações das oportunidades são apresentadas conforme
                os dados disponibilizados pelas fontes de origem. O TI Vagas
                não é o responsável pela seleção, contratação, remuneração
                ou condução dos processos seletivos.
            </p>

            <p>
                A candidatura é realizada diretamente no site da empresa
                ou da plataforma responsável pela oportunidade. O usuário
                deve conferir as informações e as condições diretamente
                na página de candidatura.
            </p>

            <p>
                O portal poderá atualizar, corrigir, remover ou deixar de
                exibir oportunidades quando identificar informações
                desatualizadas, indisponibilidade ou inconsistências.
            </p>

            <p>
                O acesso ao conteúdo de vagas depende da concordância
                com estes Termos de Uso.
            </p>
        </section>

        <section class="legal-section" id="responsabilidade">
            <h2>Termos de Responsabilidade</h2>

            <p>
                O TI Vagas atua como agregador de oportunidades e não
                representa as empresas anunciantes ou as plataformas
                externas nas quais as vagas são publicadas.
            </p>

            <p>
                Não garantimos que uma oportunidade permaneça aberta,
                que todas as informações fornecidas pela fonte estejam
                completas ou que o candidato seja selecionado.
            </p>

            <p>
                Recomendamos que o usuário confirme a autenticidade da
                oportunidade, verifique o endereço do site de candidatura
                e não forneça dados pessoais ou financeiros fora dos
                canais oficiais da empresa responsável.
            </p>

            <p>
                O TI Vagas poderá corrigir ou retirar anúncios e oportunidades
                quando houver motivos técnicos, editoriais ou de segurança.
                Isso não elimina as responsabilidades que lhe sejam atribuídas
                pela legislação aplicável.
            </p>
        </section>

        <section class="legal-section" id="privacidade">
            <h2>Política de Privacidade</h2>

            <p>
                O TI Vagas utiliza informações estritamente necessárias
                para manter a navegação, proteger a aplicação e oferecer
                seus recursos.
            </p>

            <p>
                Quando o usuário autoriza a localização pelo navegador,
                as coordenadas são utilizadas para identificar o município
                e disponibilizar oportunidades da sua região. Nesta versão,
                essas informações são mantidas na sessão de navegação
                e não são utilizadas para criar um cadastro de usuário.
            </p>

            <p>
                Se o usuário não autorizar a localização do navegador, o portal
                poderá enviar o endereço IP ao serviço externo IPWho para obter
                uma estimativa de cidade e estado. A localização por IP pode
                estar incorreta, especialmente em redes móveis, corporativas
                ou com VPN, e é utilizada somente para informar a estimativa
                exibida na página. Ela nunca libera vagas locais. O TI Vagas
                não grava o endereço IP em seu banco de dados; mantém em cache
                o resultado aproximado associado a uma chave derivada do IP
                por até 12 horas. O serviço IPWho recebe o IP e poderá tratá-lo
                conforme sua própria política de privacidade.
            </p>

            <p>
                O portal utiliza cookies necessários para manter a sessão,
                registrar a confirmação dos termos e lembrar a confirmação
                do aviso de cookies.
            </p>

            <p>
                Nesta primeira versão, não implementamos cookies de
                rastreamento publicitário ou personalização de anúncios
                baseada no comportamento do usuário.
            </p>

            <p>
                A publicidade poderá ser contextual, considerando o conteúdo
                do portal, sem depender de rastreamento individual.
            </p>

            <p>
                O processo de candidatura ocorre em plataformas externas.
                Ao sair do TI Vagas, o usuário estará sujeito às políticas
                de privacidade e aos termos do respectivo serviço.
            </p>

            <p>
                Esta política deverá ser atualizada sempre que novas
                funcionalidades ou integrações alterarem o tratamento
                de dados pessoais.
            </p>
        </section>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('welcome-form');
    const termsCheckbox = document.getElementById('terms-accepted');

    const locationButton = document.getElementById('enable-location');
    const limitedButton = document.getElementById('continue-limited');

    const statusInput = document.getElementById('location-status');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const stateCodeInput = document.getElementById('state-code');
    const cityNameInput = document.getElementById('city-name');

    const message = document.getElementById('location-message');
    const termsAlreadyAccepted = @json($termsAccepted);
    const REVERSE_GEOCODING_TIMEOUT_MS = 8000;

    let submitting = false;

    const stateCodes = {
        'acre': 'AC',
        'alagoas': 'AL',
        'amapa': 'AP',
        'amazonas': 'AM',
        'bahia': 'BA',
        'ceara': 'CE',
        'distrito federal': 'DF',
        'espirito santo': 'ES',
        'goias': 'GO',
        'maranhao': 'MA',
        'mato grosso': 'MT',
        'mato grosso do sul': 'MS',
        'minas gerais': 'MG',
        'minas gerais': 'MG',
        'para': 'PA',
        'paraiba': 'PB',
        'parana': 'PR',
        'pernambuco': 'PE',
        'piaui': 'PI',
        'rio de janeiro': 'RJ',
        'rio grande do norte': 'RN',
        'rio grande do sul': 'RS',
        'rondonia': 'RO',
        'roraima': 'RR',
        'santa catarina': 'SC',
        'sao paulo': 'SP',
        'sergipe': 'SE',
        'tocantins': 'TO'
    };

    function updateButtons() {
        const accepted = termsAlreadyAccepted || termsCheckbox.checked;

        locationButton.disabled = !accepted;
        limitedButton.disabled = !accepted;
    }

    function normalizeText(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase();
    }

    function identifyStateCode(address) {
        const isoCode =
            address['ISO3166-2-lvl4'] ||
            address['ISO3166-2-lvl3'] ||
            '';

        const isoMatch = String(isoCode).match(/BR-([A-Z]{2})/i);

        if (isoMatch) {
            return isoMatch[1].toUpperCase();
        }

        const stateName = normalizeText(address.state);

        return stateCodes[stateName] || null;
    }

    function identifyCityName(address) {
        return address.city ||
            address.town ||
            address.municipality ||
            address.village ||
            address.city_district ||
            '';
    }

    async function getLocation(latitude, longitude) {
        const url = new URL(
            'https://nominatim.openstreetmap.org/reverse'
        );

        url.searchParams.set('format', 'jsonv2');
        url.searchParams.set('lat', latitude);
        url.searchParams.set('lon', longitude);
        url.searchParams.set('zoom', '10');
        url.searchParams.set('addressdetails', '1');
        url.searchParams.set('accept-language', 'pt-BR');

        const controller = new AbortController();
        const timer = setTimeout(function () {
            controller.abort();
        }, REVERSE_GEOCODING_TIMEOUT_MS);

        let data;

        try {
            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error('Não foi possível identificar o estado.');
            }

            data = await response.json();
        } finally {
            clearTimeout(timer);
        }

        if (!data.address) {
            throw new Error('A resposta de localização não contém endereço.');
        }

        const stateCode = identifyStateCode(data.address);

        if (!stateCode) {
            throw new Error('Não foi possível identificar a UF.');
        }

        return {
            stateCode: stateCode,
            cityName: identifyCityName(data.address)
        };
    }

    termsCheckbox.addEventListener('change', function () {
        updateButtons();

        if (termsCheckbox.checked) {
            message.textContent =
                'Termos aceitos. Agora você pode escolher como deseja continuar.';
        } else {
            message.textContent =
                'Marque a opção de aceite dos termos para habilitar os botões e continuar.';
        }
    });

    updateButtons();

    if (termsAlreadyAccepted) {
        message.textContent =
            'Termos já aceitos. Você pode ativar a localização para liberar as vagas da sua região.';
    }

    function resetLocationFields() {
        statusInput.value = 'limited';
        latitudeInput.value = '';
        longitudeInput.value = '';
        stateCodeInput.value = '';
        cityNameInput.value = '';
    }

    function submitForm() {
        if (submitting) {
            return;
        }

        submitting = true;
        form.submit();
    }

    function continueWithLimitedLocation() {
        if (submitting) {
            return;
        }

        resetLocationFields();
        submitForm();
    }

    limitedButton.addEventListener('click', resetLocationFields);

    locationButton.addEventListener('click', function () {
        if (!termsAlreadyAccepted && !termsCheckbox.checked) {
            termsCheckbox.reportValidity();
            return;
        }

        if (!navigator.geolocation) {
            message.textContent =
                'Seu navegador não disponibiliza localização. Continuando com experiência limitada...';
            continueWithLimitedLocation();
            return;
        }

        locationButton.disabled = true;
        locationButton.textContent = 'Obtendo localização...';

        message.textContent =
            'Aguardando a autorização de localização do navegador. ' +
            'Se preferir, continue com experiência limitada.';

        navigator.geolocation.getCurrentPosition(
            async function (position) {
                if (submitting) {
                    return;
                }

                const latitude = position.coords.latitude;
                const longitude = position.coords.longitude;

                try {
                    message.textContent =
                        'Localização obtida. Identificando seu município...';

                    const location = await getLocation(
                        latitude,
                        longitude
                    );

                    statusInput.value = 'available';
                    latitudeInput.value = latitude;
                    longitudeInput.value = longitude;
                    stateCodeInput.value = location.stateCode;
                    cityNameInput.value = location.cityName;

                    if (submitting) {
                        return;
                    }

                    message.textContent =
                        'Localização identificada (' + location.stateCode + '). Continuando...';

                    submitForm();
                } catch (error) {
                    resetLocationFields();

                    message.textContent =
                        'Não foi possível identificar sua localização. Continuando com experiência limitada...';

                    continueWithLimitedLocation();
                }
            },
            function (error) {
                if (submitting) {
                    return;
                }

                resetLocationFields();

                if (error.code === error.PERMISSION_DENIED) {
                    message.textContent =
                        'A localização não foi autorizada. Continuando com experiência limitada...';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    message.textContent =
                        'Não foi possível identificar sua localização. Continuando com experiência limitada...';
                } else {
                    message.textContent =
                        'O tempo para obter a localização terminou. Continuando com experiência limitada...';
                }

                continueWithLimitedLocation();
            },
            {
                enableHighAccuracy: false,
                timeout: 12000,
                maximumAge: 300000
            }
        );
    });
});
</script>

@endsection
