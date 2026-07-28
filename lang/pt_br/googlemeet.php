<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     mod_googlemeet
 * @category    string
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['at'] = 'às';
$string['issuerid'] = 'Serviço OAuth';
$string['issuerid_desc'] = '<a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/Como-criar-o-ID-do-cliente-e-a-Chave-secreta-do-cliente" target="_blank">Como configurar um Serviço OAuth</a>';
$string['calendareventname'] = '{$a} está agendado para';
$string['checkweekdays'] = 'Selecione pelo menos um dia da semana para a recorrência.';
$string['date'] = 'Data';
$string['duration'] = 'Duração';
$string['earlierto'] = 'A data do evento não pode ser anterior à data de início do curso ({$a}).';
$string['emailcontent'] = 'Conteúdo do e-mail';
$string['emailcontent_default'] = '<p>Olá %userfirstname%,</p>
<p>Este lembrete é para lembrar você de que haverá um evento do Google Meet em %coursename%</p>
<p><b>%googlemeetname%</b></p>
<p>Quando: %eventdate% %duration% %timezone%</p>
<p>Link de acesso: %url%</p>';
$string['emailcontent_help'] = 'Quando uma notificação é enviada a um aluno, ele obtém o conteúdo do email desse campo. Os seguintes curingas podem ser usados:
<ul>
<li>%userfirstname%</li>
<li>%userlastname%</li>
<li>%coursename%</li>
<li>%googlemeetname%</li>
<li>%eventdate%</li>
<li>%duration%</li>
<li>%timezone%</li>
<li>%url%</li>
<li>%cmid%</li>
</ul>';
$string['entertheroom'] = 'Entrar na sala';
$string['eventdate'] = 'Data do evento';
$string['eventdetails'] = 'Detalhes do evento';
$string['from'] = 'das';
$string['googlemeet:addinstance'] = 'Adicionar novo Google Meet™ para Moodle';
$string['googlemeet:editrecording'] = 'Editar as gravações';
$string['googlemeet:managemeeting'] = 'Gerenciar a reunião do Google Agenda';
$string['googlemeet:receivenotification'] = 'Receber lembretes de reunião';
$string['googlemeet:removerecording'] = 'Remover as gravações';
$string['googlemeet:syncgoogledrive'] = 'Descobrir gravações do Google Meet';
$string['googlemeet:view'] = 'Ver Google Meet™ para Moodle';
$string['hide'] = 'Ocultar';
$string['invalidactivitycontext'] = 'O item solicitado não pertence a esta atividade Google Meet.';
$string['invalideventenddate'] = 'O término da recorrência não pode ser anterior ao início da reunião.';
$string['invalideventendtime'] = 'O horário de término deve ser maior que o horário de início';
$string['invalidissuerid'] = 'O serviço OAuth selecionado nas configurações do "Google Meet™ para Moodle" não é suportado pelo Google';
$string['invalidintegrationmode'] = 'Selecione um modo de integração de reunião compatível.';
$string['invalidmeetingtimezone'] = 'Selecione um fuso horário IANA válido.';
$string['invalidrecurrenceinterval'] = 'O intervalo da recorrência deve ficar entre 1 e 36 semanas.';
$string['invalidschedule'] = 'A agenda da reunião é inválida. Revise as datas, o fuso horário e a recorrência.';
$string['invalidsyncaction'] = 'Esta ação de sincronização não está disponível no estado atual da reunião.';
$string['invalidstoredurl'] = 'Não é possível exibir este recurso, a URL do Google Meet é inválida.';
$string['integration'] = 'Integração da reunião';
$string['integrationlegacyupgrade'] = 'Esta atividade usa a integração legada do Google. Para salvá-la, escolha uma reunião '
    . 'gerenciada pelo Google Agenda ou informe um link existente do Meet.';
$string['integrationmode'] = 'Criação da reunião';
$string['integrationmode_help'] = 'O modo gerenciado cria e reconcilia o evento do Google Agenda de forma assíncrona '
    . 'usando sua própria autorização. O modo manual armazena um link existente do Google Meet sem gerenciar um evento '
    . 'do Agenda.';
$string['integrationmodemanaged'] = 'Criar e gerenciar com o Google Agenda';
$string['integrationmodemanual'] = 'Usar um link existente do Google Meet';
$string['jstableinfo'] = 'Mostrando {start} a {end} de {rows} gravações';
$string['jstableinfofiltered'] = 'Mostrando {start} a {end} de {rows} gravações (filtrado de {rowsTotal} gravações)';
$string['jstableloading'] = 'Carregando...';
$string['jstablenorows'] = 'Nenhuma gravação encontrada';
$string['jstableperpage'] = '{select} gravações por página';
$string['jstablesearch'] = 'Procurar...';
$string['lastsync'] = 'Última sincronização:';
$string['loading'] = 'Carregando';
$string['logintoaccount'] = 'Faça login na sua conta do Google';
$string['logintoyourgoogleaccount'] = 'Faça login na sua conta do Google para que a URL do Google Meet seja criada automaticamente';
$string['loggedinaccount'] = 'Conta do Google conectada';
$string['logout'] = 'Sair';
$string['manage'] = 'Gerenciar';
$string['managedmodecannotchange'] = 'Uma reunião gerenciada não pode ser convertida em link manual enquanto seu ciclo '
    . 'remoto no Google Agenda estiver ativo.';
$string['managedoauth'] = 'Autorização do Google Agenda';
$string['managedoauthclose'] = 'Você pode fechar esta janela e voltar ao formulário da atividade.';
$string['managedoauthconnect'] = 'Conectar Google Agenda';
$string['managedoauthconnected'] = 'O Google Agenda está conectado para este professor.';
$string['managedoauthfailed'] = 'Não foi possível concluir a autorização do Google Agenda.';
$string['managedoauthrequired'] = 'Conecte sua conta Google antes de salvar uma reunião gerenciada.';
$string['managedoauthunavailable'] = 'É necessário configurar um serviço OAuth do Google para usar reuniões gerenciadas.';
$string['managedowneronly'] = 'Somente o professor proprietário desta reunião gerenciada pode atualizar sua integração '
    . 'com o Google Agenda.';
$string['managedroomurldesc'] = 'Reuniões gerenciadas recebem o link do Meet após a sincronização em segundo plano. '
    . 'Informe um link apenas no modo manual.';
$string['meetingend'] = 'Término da reunião';
$string['meetingend_help'] = 'Selecione a data e o horário exatos de término. O término deve ser posterior ao início.';
$string['meetinglinknotready'] = 'O link do Google Meet ainda não está disponível. A atividade será atualizada após a '
    . 'sincronização.';
$string['meetingschedule'] = 'Agenda da reunião';
$string['meetingstart'] = 'Início da reunião';
$string['meetingstart_help'] = 'Selecione a data e o horário exatos de início no fuso horário da reunião.';
$string['meetingtimezone'] = 'Fuso horário da reunião';
$string['meetingtimezone_help'] = 'As datas são armazenadas como instantes absolutos. Nas recorrências semanais, o '
    . 'horário local selecionado é preservado neste fuso mesmo quando há mudança de horário de verão.';
$string['messageprovider:notification'] = 'Lembrete de início do evento do Google Meet';
$string['minutesbefore'] = 'Minutos antes';
$string['minutesbefore_help'] = 'Número de minutos antes do início do evento quando a notificação deve ser enviada.';
$string['modulename'] = 'Google Meet™ para Moodle';
$string['modulename_help'] = 'O módulo Google Meet™ para Moodle permite ao professor criar uma sala do Google Meet como recurso do curso e, após as reuniões, disponibilizar as gravações aos alunos, salvas no Google Drive.
<p>©2018 Google LLC All rights reserved.<br/>
Google Meet and the Google Meet logo are registered trademarks of Google LLC.</p>';
$string['modulenameplural'] = 'Instâncias do Google Meet™ para Moodle';
$string['multieventdateexpanded'] = 'Recorrência da data do evento expandido';
$string['multieventdateexpanded_desc'] = 'Mostrar as configurações de "Recorrência da data do evento" expandidas por padrão ao criar uma nova Sala.';
$string['name'] = 'Nome';
$string['never'] = 'Nunca';
$string['notification'] = 'Notificação';
$string['notificationexpanded'] = 'Notificação expandida';
$string['notify'] = 'Enviar notificação para o estudante';
$string['notify_help'] = 'Se marcada, uma notificação será enviada ao aluno sobre a data de início do evento.';
$string['notifycationexpanded_desc'] = 'Mostrar as configurações de "Notificação" expandidas por padrão ao criar uma nova sala.';
$string['notifytask'] = 'Tarefa de notificação do Google Meet™ para Moodle';
$string['notifytaskresult'] = 'Lembretes de reunião: {$a->events} ocorrência(s) no período e {$a->sent} mensagem(ns) enviada(s).';
$string['or'] = 'ou';
$string['overviewjoinmeeting'] = 'Entrar na reunião';
$string['overviewmeetingtime'] = 'Data da reunião';
$string['overviewsyncstatus'] = 'Estado da reunião';
$string['play'] = 'Reproduzir';
$string['pluginadministration'] = 'Administração do Google Meet™ para Moodle';
$string['pluginname'] = 'Google Meet™ para Moodle';
$string['privacy:metadata:core_calendar'] = 'A atividade usa o calendário do Moodle para publicar sua programação local.';
$string['privacy:metadata:core_message'] = 'A atividade usa as mensagens do Moodle para enviar os lembretes configurados.';
$string['privacy:metadata:core_oauth2'] = 'O núcleo do Moodle armazena a concessão OAuth e os tokens por usuário usados '
    . 'pela atividade.';
$string['privacy:metadata:google_calendar'] = 'Uma conta conectada pelo professor envia dados do evento e da conferência '
    . 'ao Google Agenda.';
$string['privacy:metadata:google_calendar:authorizedaccount'] = 'A conta Google autorizada pelo professor.';
$string['privacy:metadata:google_calendar:conference'] = 'A solicitação de criação de uma conferência do Google Meet.';
$string['privacy:metadata:google_calendar:recurrence'] = 'A regra de recorrência do evento.';
$string['privacy:metadata:google_calendar:schedule'] = 'O início e o término do evento.';
$string['privacy:metadata:google_calendar:summary'] = 'O nome da atividade Moodle usado como título do evento.';
$string['privacy:metadata:google_calendar:timezone'] = 'O fuso horário do evento.';
$string['privacy:metadata:google_meet'] = 'Uma conta conectada pelo professor envia o código da reunião ao Google Meet '
    . 'para descobrir metadados das gravações geradas.';
$string['privacy:metadata:google_meet:authorizedaccount'] = 'A conta Google autorizada pelo professor.';
$string['privacy:metadata:google_meet:meetingcode'] = 'O código exato da reunião usado para localizar registros de conferência.';
$string['privacy:metadata:google_meet:recordings'] = 'Identificadores, horários e links de reprodução das gravações geradas.';
$string['privacy:metadata:googlemeet'] = 'Armazena referências de autorização por proprietário e dados operacionais '
    . 'da sincronização.';
$string['privacy:metadata:googlemeet:calendarid'] = 'O identificador do Google Agenda selecionado.';
$string['privacy:metadata:googlemeet:conferenceid'] = 'O identificador da conferência no Google.';
$string['privacy:metadata:googlemeet:conferencestatus'] = 'O estado da criação da conferência retornado pelo Google.';
$string['privacy:metadata:googlemeet:creatoremail'] = 'O e-mail legado do organizador no Google.';
$string['privacy:metadata:googlemeet:eventid'] = 'O identificador legado do link externo do Google Agenda.';
$string['privacy:metadata:googlemeet:googleeventetag'] = 'A etiqueta de entidade do evento do Google Agenda.';
$string['privacy:metadata:googlemeet:googleeventhtmlurl'] = 'A página do evento no Google Agenda.';
$string['privacy:metadata:googlemeet:googleeventid'] = 'O identificador do evento no Google Agenda.';
$string['privacy:metadata:googlemeet:lasterrorcode'] = 'O código seguro do último erro de sincronização com o Google Agenda.';
$string['privacy:metadata:googlemeet:lasterrormessage'] = 'A mensagem higienizada do último erro de sincronização com o '
    . 'Google Agenda.';
$string['privacy:metadata:googlemeet:meetingcode'] = 'O código normalizado da reunião do Google Meet.';
$string['privacy:metadata:googlemeet:meetinguri'] = 'O link para entrar na reunião do Google Meet.';
$string['privacy:metadata:googlemeet:oauthissuerid'] = 'O serviço OAuth do Moodle usado pelo proprietário do Agenda.';
$string['privacy:metadata:googlemeet:owneruserid'] = 'O usuário Moodle proprietário da autorização do Google Agenda.';
$string['privacy:metadata:googlemeet:recordinglasterrorcode'] = 'O código seguro do último erro de descoberta de gravações.';
$string['privacy:metadata:googlemeet:recordinglasterrormessage'] = 'A mensagem higienizada do último erro de descoberta '
    . 'de gravações.';
$string['privacy:metadata:googlemeet:recordingoauthissuerid'] = 'O serviço OAuth do Moodle usado pelo proprietário das '
    . 'gravações.';
$string['privacy:metadata:googlemeet:recordingowneruserid'] = 'O usuário Moodle proprietário da autorização para '
    . 'descobrir gravações.';
$string['privacy:metadata:googlemeet:recordingsyncattempts'] = 'O número de tentativas de descoberta de gravações.';
$string['privacy:metadata:googlemeet:recordingsyncstatus'] = 'O estado da descoberta de gravações.';
$string['privacy:metadata:googlemeet:recordingtimelastattempt'] = 'O horário da última tentativa de descoberta de '
    . 'gravações.';
$string['privacy:metadata:googlemeet:requestid'] = 'O identificador de idempotência usado para solicitar uma conferência.';
$string['privacy:metadata:googlemeet:syncattempts'] = 'O número de tentativas de sincronização com o Google Agenda.';
$string['privacy:metadata:googlemeet:syncstatus'] = 'O estado da sincronização com o Google Agenda.';
$string['privacy:metadata:googlemeet:timelastattempt'] = 'O horário da última tentativa de sincronização com o Google Agenda.';
$string['privacy:metadata:googlemeet_notify_done'] = 'Armazena comprovantes dos lembretes de reunião enviados aos usuários.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'O evento local associado ao lembrete.';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'O horário em que o lembrete foi enviado.';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'O usuário Moodle que recebeu o lembrete.';
$string['privacy:metadata:googlemeet_recordings'] = 'Armazena referências compartilhadas das gravações geradas e '
    . 'publicadas na atividade.';
$string['privacy:metadata:googlemeet_recordings:createdtime'] = 'O horário de criação da gravação.';
$string['privacy:metadata:googlemeet_recordings:duration'] = 'A duração da gravação.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'O nome exibido para a gravação.';
$string['privacy:metadata:googlemeet_recordings:recordingid'] = 'O identificador do arquivo no Google Drive retornado '
    . 'pelo Google Meet.';
$string['privacy:metadata:googlemeet_recordings:visible'] = 'Se a gravação está visível para os participantes do curso.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'O link de reprodução no Google Drive retornado pelo '
    . 'Google Meet.';
$string['privacy:path:calendar'] = 'Autorização e sincronização com o Google Agenda';
$string['privacy:path:notifications'] = 'Comprovantes dos lembretes da reunião';
$string['privacy:path:recordingauthorization'] = 'Autorização das gravações do Google Meet';
$string['privacy:path:recordings'] = 'Referências compartilhadas descobertas com sua autorização';
$string['recording'] = 'Gravação';
$string['recordings'] = 'Gravações';
$string['recordingswiththename'] = 'Gravações com o nome:';
$string['reconcilependingresult'] = 'A reconciliação do Google Agenda encontrou {$a->found} reunião(ões) e enfileirou {$a->queued}.';
$string['reconcilependingtask'] = 'Reconciliar conferências pendentes do Google Meet';
$string['recurrenceeventdate'] = 'Recorrência da reunião';
$string['recurrenceeventdate_help'] = 'Ative a recorrência semanal, selecione um ou mais dias, escolha um intervalo de '
    . '1 a 36 semanas e defina o limite inclusivo da recorrência. A série pode abranger no máximo um ano.';
$string['recurrencecompatibilitynotice'] = 'Esta agenda importada contém datas individuais ou exceções de recorrência '
    . 'que o editor semanal não consegue representar com segurança. A agenda canônica foi preservada como somente '
    . 'leitura; as demais configurações da atividade ainda podem ser editadas.';
$string['recurrenceenabled'] = 'Repetir esta reunião semanalmente';
$string['recurrenceinterval'] = 'Repetir a cada (semanas)';
$string['repeatasfollows'] = 'Repita a data do evento acima da seguinte forma';
$string['repeatevery'] = 'Repetir a cada';
$string['repeaton'] = 'Repetir';
$string['repeatuntil'] = 'Repetir até';
$string['roomcreator'] = 'Organizador:';
$string['roomname'] = 'Nome da sala';
$string['roomurl'] = 'URL da sala';
$string['roomurl_desc'] = 'A URL da sala será gerada automaticamente.';
$string['roomurlexpanded'] = 'URL da sala expandido';
$string['roomurlexpanded_desc'] = 'Mostrar as configurações de "URL da sala" expandidas por padrão ao criar uma nova sala.';
$string['sessionexpired'] = 'A sessão da sua conta do Google expirou no meio do processo, faça login novamente.';
$string['show'] = 'Mostrar';
$string['strftimedm'] = '%a. %d %b.';
$string['strftimedmy'] = '%a. %d %b. %Y';
$string['strftimedmyhm'] = '%a. %d %b. %Y %H:%M';
$string['strftimehm'] = '%H:%M';
$string['syncactivitymissing'] = 'A atividade Google Meet {$a} não existe mais; a sincronização foi ignorada.';
$string['syncactionqueued'] = 'A sincronização da reunião foi enfileirada.';
$string['syncadapterunavailable'] = 'O adaptador de sincronização gerenciada com o Google Agenda ainda não está disponível.';
$string['synccalendarcancelapifailed'] = 'O Google Agenda rejeitou permanentemente a solicitação de cancelamento.';
$string['synccalendarapifailed'] = 'O Google Agenda rejeitou permanentemente a solicitação de sincronização.';
$string['synccalendarconfigurationinvalid'] = 'A configuração da integração gerenciada com o Google Agenda é inválida.';
$string['synccalendarresponseinvalid'] = 'O Google Agenda retornou um evento incompleto ou inconsistente.';
$string['syncconferencecreationfailed'] = 'O Google Agenda não conseguiu criar a conferência do Google Meet.';
$string['synccancel'] = 'Cancelar reunião';
$string['synccancelqueued'] = 'O cancelamento da reunião foi enfileirado.';
$string['syncdisconnect'] = 'Desconectar esta atividade';
$string['syncdisconnected'] = 'A autorização local do Google Agenda e os identificadores remotos foram removidos.';
$string['syncdisconnectrequirescancel'] = 'Cancele o evento no Google Agenda com sucesso antes de desconectar esta atividade.';
$string['syncinvalidintegrationmode'] = 'O modo de integração armazenado para o Google Meet é inválido.';
$string['synclegacydisconnected'] = 'A atividade Google Meet {$a} exige uma nova autorização do Google.';
$string['synclocktimeout'] = 'Não foi possível obter o bloqueio de sincronização da atividade Google Meet {$a}.';
$string['syncmanagedapifailed'] = 'A atividade Google Meet {$a} foi rejeitada pelo Google Agenda.';
$string['syncmanagedcancelled'] = 'A atividade Google Meet {$a} foi cancelada no Google Agenda.';
$string['syncmanagedconfigurationfailed'] = 'A atividade Google Meet {$a} tem uma configuração gerenciada inválida.';
$string['syncmanageddeferred'] = 'A atividade Google Meet {$a} aguarda o adaptador gerenciado do Google Agenda.';
$string['syncmanageddisconnected'] = 'A atividade Google Meet {$a} exige uma nova autorização do Google Agenda.';
$string['syncmanagedfailed'] = 'A atividade Google Meet {$a} recebeu uma falha na criação da conferência.';
$string['syncmanagedpending'] = 'A atividade Google Meet {$a} aguarda a criação da conferência pelo Google.';
$string['syncmanagedready'] = 'A atividade Google Meet {$a} está sincronizada e pronta.';
$string['syncmanagedresponsefailed'] = 'A atividade Google Meet {$a} recebeu uma resposta inválida do Google Agenda.';
$string['syncmanualready'] = 'A atividade Google Meet {$a} usa um link manual e está pronta.';
$string['syncoauthrequired'] = 'O proprietário da reunião precisa autorizar novamente o Google Agenda antes de continuar.';
$string['syncreconnect'] = 'Reconectar Google Agenda';
$string['syncreconnectrequired'] = 'O proprietário da reunião precisa reconectar o Google antes de continuar.';
$string['syncrestoredreconnectrequired'] = 'Esta reunião gerenciada restaurada precisa ser assumida e reconectada antes '
    . 'da sincronização.';
$string['syncretry'] = 'Tentar sincronizar novamente';
$string['syncstateskipped'] = 'A atividade Google Meet {$a->id} está no estado {$a->state}; a sincronização foi ignorada.';
$string['syncstatuscancelled'] = 'Cancelada';
$string['syncstatuscancelled_desc'] = 'O evento gerenciado do Google Agenda foi cancelado.';
$string['syncstatuscancelling'] = 'Cancelando';
$string['syncstatuscancelling_desc'] = 'O Moodle aguarda a conclusão do cancelamento remoto.';
$string['syncstatusdisconnected'] = 'Autorização necessária';
$string['syncstatusdisconnected_desc'] = 'O proprietário precisa reconectar o Google Agenda para continuar a sincronização.';
$string['syncstatusdraft'] = 'Preparando';
$string['syncstatusdraft_desc'] = 'A reunião está salva localmente e aguarda o enfileiramento.';
$string['syncstatusfailed'] = 'Falha na sincronização';
$string['syncstatusfailed_desc'] = 'A última tentativa de sincronização falhou. Um professor ou administrador pode '
    . 'consultar os detalhes técnicos.';
$string['syncstatuspending'] = 'Criando conferência';
$string['syncstatuspending_desc'] = 'O evento existe no Google Agenda, que ainda está criando a conferência do Meet.';
$string['syncstatusqueued'] = 'Na fila';
$string['syncstatusqueued_desc'] = 'A reunião aguarda uma tarefa de sincronização em segundo plano.';
$string['syncstatusready'] = 'Pronta';
$string['syncstatusready_desc'] = 'O evento do Google Agenda e a conferência do Meet estão sincronizados.';
$string['syncstatussyncing'] = 'Sincronizando';
$string['syncstatussyncing_desc'] = 'O Moodle está reconciliando a reunião com o Google Agenda.';
$string['synchronizationstatus'] = 'Estado da sincronização';
$string['syncdiagnosticdetails'] = 'Detalhes técnicos';
$string['syncerrorcode'] = 'Código do erro';
$string['syncerrormessage'] = 'Mensagem segura do erro';
$string['synclastattempt'] = 'Última tentativa: {$a}';
$string['synchronisetask'] = 'Sincronizar atividade do Google Meet';
$string['thereisnorecordingtoshow'] = 'Não há gravação para mostrar.';
$string['timeahead'] = 'Uma reunião recorrente não pode exceder um ano. Ajuste o início ou o término da recorrência.';
$string['timedate'] = '%d/%m/%Y %H:%M';
$string['to'] = 'até';
$string['today'] = 'Hoje';
$string['upcomingevents'] = 'Próximos eventos';
$string['url'] = '';
$string['url_failed'] = 'É obrigatório uma URL válida do Google Meet';
$string['url_help'] = 'Ex. https://meet.google.com/aaa-aaaa-aaa';
$string['visible'] = 'Visível';
$string['week'] = 'Semana(s)';
$string['recordingissuerid'] = 'Serviço OAuth para gravações';
$string['recordingissuerid_desc'] = 'Selecione um serviço OAuth do Google dedicado à descoberta de gravações. Ele '
    . 'deve ser diferente do serviço usado para Agenda/login e solicitar somente o escopo de leitura do Google Meet '
    . 'quando o professor conectar as gravações.';
$string['recordingowneronly'] = 'Somente o professor que conectou a descoberta de gravações pode sincronizar esta atividade.';
$string['recordingsoauthconnect'] = 'Conectar gravações do Google Meet';
$string['recordingsoauthconnected'] = 'A descoberta de gravações do Google Meet está conectada para este professor.';
$string['recordingsoauthfailed'] = 'Não foi possível concluir a autorização das gravações do Google Meet.';
$string['recordingsoauthrequired'] = 'O proprietário das gravações precisa reconectar o Google Meet antes de continuar '
    . 'a descoberta.';
$string['recordingsoauthunavailable'] = 'Configure um serviço OAuth do Google dedicado à descoberta de gravações. Ele '
    . 'deve ser diferente do serviço usado para Agenda/login.';
$string['recordingsactivitymissing'] = 'A descoberta de gravações ignorou a atividade Google Meet {$a}, pois ela não '
    . 'existe mais.';
$string['recordingsapifailed'] = 'O Google Meet rejeitou a solicitação de descoberta de gravações.';
$string['recordingsdiscovertask'] = 'Descobrir gravações do Google Meet';
$string['recordingsdisconnect'] = 'Desconectar descoberta de gravações';
$string['recordingsdisconnected'] = 'A descoberta de gravações foi desconectada. As referências existentes foram preservadas.';
$string['recordingsinvalidaction'] = 'A ação solicitada para as gravações é inválida.';
$string['recordingsqueued'] = 'A descoberta de gravações foi enfileirada.';
$string['recordingsresponseinvalid'] = 'O Google Meet retornou uma resposta de gravação inválida.';
$string['recordingsstateskipped'] = 'A descoberta de gravações ignorou a atividade {$a->id} no estado {$a->state}.';
$string['recordingssync'] = 'Descobrir gravações';
$string['recordingssynced'] = 'A descoberta de gravações foi concluída para a atividade {$a->id}: {$a->count} '
    . 'artefato(s) gerado(s) encontrado(s).';
$string['recordingsyncstatus'] = 'Estado da descoberta de gravações';
$string['recordingsyncstatusdisconnected'] = 'Desconectada';
$string['recordingsyncstatusfailed'] = 'Falhou';
$string['recordingsyncstatusqueued'] = 'Na fila';
$string['recordingsyncstatusready'] = 'Pronta';
$string['recordingsyncstatussyncing'] = 'Sincronizando';
