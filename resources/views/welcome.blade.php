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
                    Se não autorizar ou se a localização estiver indisponível,
                    você ainda poderá navegar pelo TI Vagas, mas terá uma
                    experiência limitada: os recursos que dependem da
                    localização não estarão disponíveis.
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
                    value="limited"
                >

                <input
                    type="hidden"
                    name="latitude"
                    id="latitude"
                >

                <input
                    type="hidden"
                    name="longitude"
                    id="longitude"
                >

                <div class="terms-acceptance">
                    <label>
                        <input
                            type="checkbox"
                            name="terms"
                            value="1"
                            required
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
                    >
                        Ativar localização e continuar
                    </button>

                    <button
                        type="submit"
                        id="continue-limited"
                        class="button welcome-secondary-button"
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
                    Você poderá escolher se autoriza a localização
                    quando clicar no botão acima.
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
                as coordenadas poderão ser utilizadas para funcionalidades
                regionais. Nesta versão, essas informações são mantidas
                na sessão de navegação e não são utilizadas para criar
                um cadastro de usuário.
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
    const locationButton = document.getElementById('enable-location');
    const statusInput = document.getElementById('location-status');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const message = document.getElementById('location-message');

    locationButton.addEventListener('click', function () {
        if (!navigator.geolocation) {
            message.textContent =
                'Seu navegador não disponibiliza localização. Você pode continuar com experiência limitada.';
            return;
        }

        locationButton.disabled = true;
        locationButton.textContent = 'Obtendo localização...';

        message.textContent =
            'Aguardando a autorização de localização do navegador.';

        navigator.geolocation.getCurrentPosition(
            function (position) {
                statusInput.value = 'available';
                latitudeInput.value = position.coords.latitude;
                longitudeInput.value = position.coords.longitude;

                message.textContent =
                    'Localização autorizada. Continuando...';

                form.requestSubmit();
            },
            function (error) {
                statusInput.value = 'limited';
                latitudeInput.value = '';
                longitudeInput.value = '';

                locationButton.disabled = false;
                locationButton.textContent =
                    'Tentar autorizar localização novamente';

                if (error.code === error.PERMISSION_DENIED) {
                    message.textContent =
                        'A localização não foi autorizada. Você pode continuar com experiência limitada.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    message.textContent =
                        'Não foi possível identificar sua localização. Você pode continuar com experiência limitada.';
                } else {
                    message.textContent =
                        'O tempo para obter a localização terminou. Você pode tentar novamente ou continuar com experiência limitada.';
                }
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
