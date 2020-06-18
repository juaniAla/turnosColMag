<?php

namespace App\Controller;

use App\Entity\Persona;
use App\Entity\Organismo;
use App\Entity\Turno;
use App\Entity\TurnosDiarios;
use App\Entity\TurnoRechazado;
use App\Form\PersonaType;
use App\Form\Turno3Type;
use App\Form\Turno4Type;
use App\Form\Turno5Type;
use App\Form\TurnoType;
use App\Form\TurnoRechazarType;
use App\Repository\LocalidadRepository;
use App\Repository\OficinaRepository;
use App\Repository\OrganismoRepository;
use App\Repository\TurnoRepository;
use App\Repository\TurnosDiariosRepository;
use Knp\Component\Pager\PaginatorInterface;
use DateInterval;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;

class TurnoController extends AbstractController
{

    /**
     * @Route("/turno", name="turno_index", methods={"GET", "POST"})
     * 
     * @IsGranted("IS_AUTHENTICATED_FULLY")
     */
    public function index(Request $request, TurnoRepository $turnoRepository, PaginatorInterface $paginator, SessionInterface $session): Response
    {
        // Procesa filtro y lo mantiene en sesión del usuario
        if (is_null($session->get('filtroMomentoTurnos'))) { // Verifica si es la primera vez que ingresa el usuario
            // Establece el primero por defecto (Turnos de Hoy Asignados)
            $filtroMomento = 2;
            $filtroEstado = 1;
            $filtroOficina = '';
        } else {
            if (is_null($request->request->get('filterMoment'))) { // Verifica si ingresa sin indicación de filtro (refresco de la opción de cambio de estado o llamada desde otro lado)
                // Mantiene filtro de estado y momento
                $filtroMomento = $session->get('filtroMomentoTurnos');
                $filtroEstado = $session->get('filtroEstadoTurnos');

                // Analiza el parámetro de Oficina
                if ($request->query->get('cboOficina')) {
                    // La llamada viene por GET (Ej. enlace desde. "enlaestadística/Ocupación de Agenda")
                    $filtroOficina = $request->query->get('cboOficina');
                } else {
                    // Se produjo un cambio desde el panel de acciones. Se recarga la vista con los parámetros de filtro tal como están.                    
                    // Mantiene el filtro actual
                    $filtroOficina = $session->get('filtroOficinaTurnos');
                }
            } else {
                // Activa el filtro seleccionado
                $filtroMomento = $request->request->get('filterMoment');
                $filtroEstado = $request->request->get('filterState');
                $filtroOficina = $request->request->get('cboOficina');
            }
        }
        $session->set('filtroMomentoTurnos', $filtroMomento); // Almacena en session el filtro actual
        $session->set('filtroEstadoTurnos', $filtroEstado); // Almacena en session el filtro actual
        $session->set('filtroOficinaTurnos', $filtroOficina); // Almacena en session el filtro actual
        $session->set('escanerCodigo', 0); // Marca que se encuentra activa la lista de turnos y no la funcionalidad de escaneo de códigos de turno

        // Obtiene un arreglo asociativo con valores para las fechas Desde y Hasta que involucra el filtro de momento
        $rango = $this->obtieneMomento($filtroMomento);

        // Procesa filtro de Estado
        switch ($filtroEstado) {
            case 1:
                $estado = 1; // No atendidos
                break;
            case 2:
                $estado = 2; // Atendidos
                break;
            case 9:
                $estado = 9; // Todos
                break;
        }
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_AUDITORIA_GESTION')) {
            // Busca los turnos en función a los estados de todas las oficinas
            if ($filtroOficina) {
                $turnosOtorgados = $pagination = $paginator->paginate($turnoRepository->findWithRoleUser($rango, $estado, $filtroOficina), $request->query->getInt('page', 1), 100);
            } else {
                $turnosOtorgados = $pagination = $paginator->paginate($turnoRepository->findByRoleAdmin($rango, $estado), $request->query->getInt('page', 1), 100);
            }
        } else {
            if ($this->isGranted('ROLE_USER')) {
                // Busca los turnos en función a los estados de la oficina a la que pertenece el usuario
                $oficinaUsuario = $this->getUser()->getOficina();
                $turnosOtorgados = $pagination = $paginator->paginate($turnoRepository->findWithRoleUser($rango, $estado, $oficinaUsuario), $request->query->getInt('page', 1), 100);
            }
        }

        return $this->render('turno/index.html.twig', [
            'filtroMomento' => $filtroMomento,
            'filtroEstado' => $filtroEstado,
            'filtroOficina' => $filtroOficina,
            'turnos' => $turnosOtorgados,
        ]);
    }

    /**
     * Alta generada automáticamente. No se utilizará pero no se quiso borrar el método por las dudas
     * 
     * @Route("/turno/new", name="turno_new", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    function new(Request $request, LocalidadRepository $localidadRepository): Response
    {
        $turno = new Turno();
        $form = $this->createForm(TurnoType::class, $turno);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
        }

        return $this->render('turno/new.html.twig', [
            'turno' => $turno,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Wizard 1/4: Datos del Solicitante
     * 
     * @Route("/TurnosWeb/solicitante", name="turno_new2", methods={"GET","POST"})
     */
    public function new2(Request $request, SessionInterface $session): Response
    {
        $session->start();
        
        $persona = new Persona();
        $form = $this->createForm(PersonaType::class, $persona);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // El nombre y apellido de la persona los fuerzo a mayúsculas
            $persona->setApellido(mb_strtoupper($persona->getApellido()));
            $persona->setNombre(mb_strtoupper($persona->getNombre()));

            if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                $persona->setDni($persona->getOrganismo()->getCodigo()); // Guardo el código de Organismo en el DNI de la Persona
            }

            $session->set('persona', $persona);
            return $this->redirectToRoute('turno_new3');
        }

        // En organismo asigno el valor del último organismo seleccionado almacenado en la cookie del usuario
        // Si no existe la cookie o si el modo de operación es de TURNOS_WEB asigno 0
        return $this->render('persona/new.html.twig', [
            'persona' => $persona,
            'organismo' => ( $_ENV['SISTEMA_ORALIDAD_CIVIL'] ? ($request->cookies->get('organismo') ? $request->cookies->get('organismo') : 0) : 0),
            'form' => $form->createView(),
        ]);
    }

    /**
     * Wizard 2/4: Selección de Organismo
     * 
     * @Route("/TurnosWeb/oficina", name="turno_new3", methods={"GET","POST"})
     */
    public function new3(SessionInterface $session, Request $request): Response
    {
        $persona = $session->get('persona');

        // Si viene sin instancia de persona lo redirige al paso de selección de persona
        if (!$persona) {
            return $this->redirectToRoute('turno_new2');
        }

        $turno = new Turno();
        $turno->setPersona($persona);
        $form = $this->createForm(Turno3Type::class, $turno);

        if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
            $form->get('notebook')->setData(true);
            $form->get('zoom')->setData(true);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('turno', $turno);
            return $this->redirectToRoute('turno_new4');
        }

        return $this->render('turno/new3.html.twig', [
            'turno' => $turno,
            'persona' => $persona,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Wizard 3/4: Selección de Fecha y Hora
     * 
     * @Route("/TurnosWeb/fechaHora", name="turno_new4", methods={"GET","POST"})
     */
    public function new4(SessionInterface $session, Request $request, TurnoRepository $turnoRepository): Response
    {
        $persona = $session->get('persona');
        $turno = $session->get('turno');

        // Si viene sin instancia de persona lo redirige al paso de selección de persona
        if (!$persona) {
            return $this->redirectToRoute('turno_new2');
        }
        // Si viene sin instancia de turno o sin oficina seleccionada lo redirige al paso de selección de oficina
        if (!$turno || !$turno->getOficina()) {
            return $this->redirectToRoute('turno_new3');
        }

        $oficinaId = $turno->getOficina()->getId();
        $primerDiaDisponible = $turnoRepository->findPrimerDiaDisponibleByOficina($oficinaId);
        $ultimoDiaDisponible = $turnoRepository->findUltimoDiaDisponibleByOficina($oficinaId);

        $form = $this->createForm(Turno4Type::class, $turno);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('turno', $persona);
            $session->set('turno', $turno);

            return $this->redirectToRoute('turno_new5');
        }

        return $this->render('turno/new4.html.twig', [
            'turno' => $turno,
            'persona' => $persona,
            'oficinaID' => $oficinaId,
            'primerDiaDisponible' => $primerDiaDisponible,
            'ultimoDiaDisponible' => $ultimoDiaDisponible,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Wizard 4/4: Confirmación del Turno
     * 
     * @Route("/TurnosWeb/confirmacion", name="turno_new5", methods={"GET","POST"})
     */
    public function new5(SessionInterface $session, Request $request, TurnoRepository $turnoRepository, TurnosDiariosRepository $turnosDiariosRepository, LoggerInterface $logger): Response
    {
        $persona = $session->get('persona');
        $turno = $session->get('turno');

        // Si viene sin instancia de persona lo redirige al paso de selección de persona
        if (!$persona) {
            return $this->redirectToRoute('turno_new2');
        }
        // Si viene sin instancia de turno o sin oficina seleccionada lo redirige al paso de selección de oficina
        if (!$turno || !$turno->getOficina()) {
            return $this->redirectToRoute('turno_new3');
        }
        // Si viene sin fecha y hora seleccionada lo redirige al paso de selección de fecha y hora
        if (!$turno->getFechaHora()) {
            return $this->redirectToRoute('turno_new4');
        }

        // Busco la localidad del Organismo vinculado (caso de Oralidad Civil)
        $localidadOrganismo = '';
        if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
            $orgId = $persona->getDni();

            $entityManager = $this->getDoctrine()->getManager();
            
            $organismo = $entityManager->getRepository(Organismo::class)->findOneBy(['codigo' => $orgId]);
            $localidadOrganismo =$organismo->getLocalidad()->getLocalidad();
        }
        
        $form = $this->createForm(Turno5Type::class, $turno);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $turnoActualizar = $turnoRepository->findTurnoLibre($turno->getOficina()->getId(), $turno->getFechaHora());

            // Verifico si el turno no se ocupó
            // OJO que si la concurrencia es alta este control no es infalible!
            // Entre el find() y el flush() hay un marco microtemporal
            // En caso de fallar el control, el primero en grabar será sobreescrito por el segundo.
            // El primero recibió notificación del turno por correo pero la Oficina no lo va a tener registrado.
            if (!$turnoActualizar) {
                // Turno Ocupado
                return $this->redirectToRoute('turnoOcupado');
            } else {
                // Turno Libre. Grabo.
                $entityManager = $this->getDoctrine()->getManager();

                // Si se trata de agenda de Oralidad asocio el Organismo a la Persona para que se persista Persona en la BD sin problemas
                if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                    $persona->setOrganismo($organismo);
                    $turnoActualizar->setNotebook($turno->getNotebook());
                    $turnoActualizar->setZoom($turno->getZoom());    
                }

                $turnoActualizar->setMotivo($turno->getMotivo());
                $turnoActualizar->setPersona($persona);
                $session->set('turno', $turnoActualizar); // Guardo para armar luego el código QR en base al ID del turno obtenido

                // Cuento turnos que se ocupan por día (con propósitos estadísticos)
                $cuentoTurnosdelDia = $turnosDiariosRepository->findByOficinaByFecha($turnoActualizar->getOficina(), date('d/m/Y'));
                if ($cuentoTurnosdelDia) {
                    // Acumulo 
                    $cuentoTurnosdelDia->setCantidad($cuentoTurnosdelDia->getCantidad() + 1);
                } else {
                    // Primer turno del día
                    $cuentoTurnosdelDia = new TurnosDiarios();
                    $cuentoTurnosdelDia->setOficina($turnoActualizar->getOficina());
                    $cuentoTurnosdelDia->setFecha(new \DateTime(date("Y-m-d")));
                    $cuentoTurnosdelDia->setCantidad(1);
                }

                $entityManager->persist($persona);
                $entityManager->persist($cuentoTurnosdelDia);
                $entityManager->flush();
                $this->addFlash('success', 'Su turno ha sido otorgado satisfactoriamente');
                $logger->info(
                    'Turno Otorgado',
                    [
                        'Oficina' => $turnoActualizar->getOficina()->getOficinayLocalidad(),
                        'Turno' => $turnoActualizar->getTurno(),
                        'Solicitante' => $turnoActualizar->getPersona()->getPersona() . ($turnoActualizar->getPersona()->getOrganismo() ? ' | ' . $turnoActualizar->getPersona()->getOrganismo() : '')
                    ]
                );
            }

            return $this->redirectToRoute('emailConfirmacionTurno');
        }

        return $this->render('turno/new5.html.twig', [
            'turno' => $turno,
            'persona' => $persona,
            'localidadOrganismo' => $localidadOrganismo,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Wizard 4/4: Notificación de Turno Ocupado
     * 
     * Notifica que el turno se ocupó y lo redirige a seleccionar otra fecha/hora
     * 
     * @Route("/TurnosWeb/turnoOcupado", name="turnoOcupado", methods={"GET","POST"})
     */
    public function turnoOcupado(SessionInterface $session, Request $request, TurnoRepository $turnoRepository, LoggerInterface $logger): Response
    {
        $persona = $session->get('persona');
        $turno = $session->get('turno');

        $form = $this->createForm(Turno5Type::class, $turno);
        $form->handleRequest($request);
       
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->redirectToRoute('turno_new4');
        }

        $logger->info(
            'Turno Ocupado',
            [
                'Oficina' => ($turno && $turno->getOficina() ? $turno->getOficina()->getOficinayLocalidad() : 'No se pudo obtener información de la Oficina'),
                'Turno' => ($turno ? $turno->getTurno()  : 'No se pudo obtener información del Turno'),
                'Solicitante' => ($turno && $turno->getPersona() ? $turno->getPersona()->getPersona() . ($turno->getPersona()->getOrganismo() ? ' | ' . $turno->getPersona()->getOrganismo() : '') : 'No se pudo obtener información de la Persona'),
            ]
        );

        return $this->render('turno/turno_ocupado.html.twig', [
            'turno' => $turno,
            'persona' => $persona,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Notificación por correo electrónico del Turno Otorgado
     * 
     * @Route("/TurnosWeb/notificacion", name="emailConfirmacionTurno", methods={"GET","POST"})
     */
    public function sendEmail(SessionInterface $session, MailerInterface $mailer, LoggerInterface $logger)
    {
        $turno = $session->get('turno');
        
        if (!is_null($turno) && !is_null($turno->getPersona())) {

            // Si la persona ingresó un correo, envía una notificación con los datos del turno
            if ($turno->getPersona()->getEmail()) {
                // Establece que plantilla de correo se utilizará en función al tipo de Sistema
                $mailTemplate = 'turno/email_confirmacion_turno_web.html.twig';
                if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                    $mailTemplate = 'turno/email_confirmacion_turno_oralidad.html.twig';
                }

                $fromAdrress = $_ENV['MAIL_FROM'];
                $email = (new TemplatedEmail())
                    ->from($fromAdrress)
                    ->to($turno->getPersona()->getEmail())
                    ->subject('Poder Judicial Santa Fe - Confirmación de Turno')
                    ->htmlTemplate($mailTemplate)
                    ->context([
                        'expiration_date' => new \DateTime('+7 days'),
                        'turno' => $turno,
                    ]);
                $mailer->send($email);
                $this->addFlash('info', 'Se ha enviado un correo a la dirección ' . $turno->getPersona()->getEmail());
                $logger->info(
                    'Notificación Enviada',
                    [
                        'Destinatario' => $turno->getPersona()->getPersona(),
                        'Dirección' => $turno->getPersona()->getEmail()
                    ]
                );
            }
            return $this->redirectToRoute('comprobanteTurno');
        } else {
            // Si el turno se perdió de session lanzo una Exception. El usuario ve error.html y logueo en el log
            $logger->info(
                'Notificación NO Enviada. $turno is null',
                [
                    'Destinatario' => '',
                    'Dirección' => ''
                ]
            );
            throw new \Exception('El turno obtenido de la sessión se obtuvo nulo. Chequee en aplication.log si el turno fue confirmado.');
        }        
    }

    /**
     * Comprobante del Turno Otorgado
     * 
     * @Route("/TurnosWeb/comprobante", name="comprobanteTurno", methods={"GET","POST"})
     */
    public function comprobanteTurno(Request $request, SessionInterface $session, UrlGeneratorInterface $urlGenerator)
    {
        $turno = $session->get('turno');  

        // Si viene sin instancia de turno o sin ID de Turno lo redirige a la página de portada
        if (!$turno || !$turno->getId()) {
            return $this->redirectToRoute('main');
        }
        
        $form = $this->createForm(Turno5Type::class, $turno);
        $form->handleRequest($request);
        

        // Encripto el ID del turno para el Código de Barras
        
        // TODO Aparentemente el lector de códigos del PJ al levantar el código no reconoce los caracteres + e =
        //      ¿Probar con otro tipo de codificación? La utilizada es C128.
        $hash = $this->encrypt($turno->getId());

        // Datos del código QR
        if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
            $solicitante = $form->getData()->getPersona()->getOrganismo()->getOrganismo() . '(' . $form->getData()->getPersona()->getOrganismo()->getLocalidad() .')';
        }
        if ($_ENV['SISTEMA_TURNOS_WEB'] || $_ENV['SISTEMA_TURNOS_MPE']) {
            $solicitante = $form->getData()->getPersona()->getApellido() . ',' . $form->getData()->getPersona()->getNombre();
        }

        $fechaHora = 'Turno ' . $form->getData()->getFechaHora()->format('d/m/Y') . ' a las ' . $form->getData()->getFechaHora()->format('H:i') .'hs.';
        $datosAdicionales = $form->getData()->getMotivo();
        $qr = $fechaHora  . "\n \n" . $solicitante . "\n \n" . $datosAdicionales;

        $ruta = $urlGenerator->generate('turno_barcode', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?codigo=' . $hash;

        if ($form->isSubmitted() && $form->isValid()) {
            // Finalizó el proceso de Solicitud de Turnos. Vuelve a la página principal.
            return $this->redirectToRoute('main');
        }

        $response =  $this->render('turno/comprobante_turno.html.twig', [
            'turno' => $turno,
            'qr' => $qr,
            'hash' => $hash,
            'form' => $form->createView(),
        ]);

        // Si se trata de agenda de Oralidad guardo una cookie con el Organismo seleccionado
        if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {

            $orgId = $turno->getPersona()->getOrganismo()->getId();
            $time = time() + (3600 * 24 * 365); // Un año
            
            $response->headers->setCookie(new Cookie('organismo',  $orgId, $time));
        }

        return $response;
    }

    /**
     * @Route("/turno/{id}", name="turno_show", methods={"GET"})
     */
    public function show(Turno $turno): Response
    {
        return $this->render('turno/show.html.twig', [
            'turno' => $turno,
        ]);
    }

    /**
     * @Route("/turno/{id}/edit", name="turno_edit", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function edit(Request $request, Turno $turno, SessionInterface $session, LoggerInterface $logger, TranslatorInterface $translator): Response
    {      
        $form = $this->createForm(TurnoType::class, $turno);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $estados = [
                1 => $translator->trans('Sin Atender'),
                2 => $translator->trans('Atendido'),
                3 => $translator->trans('No asistió'),
                4 => $translator->trans('Rechazado')
            ];

            // Analizo que cambió
            $em =  $this->getDoctrine()->getManager();
            $uow = $em->getUnitOfWork();
            $uow->computeChangeSets();
            $changeSet = $uow->getEntityChangeSet($turno);

            // Armo salida para el Log
            $cambios = [];
            if(isset($changeSet['fechaHora'])){
                $cambios[] = ['Fecha Hora' => " '" . $changeSet['fechaHora'][0]->format('d/m/Y H:i:s') . "' a '" . $changeSet['fechaHora'][1]->format('d/m/Y H:i:s') . "' "];
            }
            if(isset($changeSet['motivo'])){
                $cambios[] = ['Motivo' => " '" . $changeSet['motivo'][0] . "' a '" . $changeSet['motivo'][1] . "' "];
            }
            if(isset($changeSet['estado'])){
                $anterior = $estados[$changeSet['estado'][0]];
                $nuevo = $estados[$changeSet['estado'][1]];
                $cambios[] = ['Estado' => " '" . $anterior . "' a '" . $nuevo . "' "];
            }          
            
            $cambios[] = ['Turno' => $turno->getTurno()];
            $cambios[] = ['Usuario' => $this->getUser()->getUsuario()];
            $logger->info('Turno Editado', $cambios);

            // Grabo
            $this->getDoctrine()->getManager()->flush();    
            $this->addFlash('success', 'Se han guardado los cambios');

            // Regreso al lugar desde que invoqué la edición
            if ($session->get('escanerCodigo')) {
                return $this->redirectToRoute('turno_barcode');
            } else {
                return $this->redirectToRoute('turno_index');
            }
        }

        return $this->render('turno/edit.html.twig', [
            'turno' => $turno,
            'persona' =>$turno->getPersona(),
            'escanerCodigo' => $session->get('escanerCodigo'),
            'oficinaUsuario' => ($this->getUser()->getOficina() ? $this->getUser()->getOficina()->getId() : ''),
            'form' => $form->createView(),
        ]);
    }


    /**
     * @Route("/codeScanner", name="turno_barcode", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function barcode(Request $request, TurnoRepository $turnoRepository, SessionInterface $session, LoggerInterface $logger): Response
    {
        $error='';

        //Construyo el formulario al vuelo
        // Verifico si recibo parámetro por GET y lo traslado al formulario
        $data = [
            'codigo' => (($request->query->get('codigo')) ? $request->query->get('codigo') : '')
        ];

        $form = $this->createFormBuilder($data)
            ->add('codigo', null,
                [
                    'label' => 'Código',
                    'required' => true,
                    'attr' => array('autofocus' => null, 'maxlength' => '50'),
                    'help' => 'Ingrese Código del talón o escanee el Código de Barras o el Código QR',
                ])
            ->add('save', SubmitType::class, [
                'label' => 'Confirmar',
                'attr' => ['class' => 'btn btn-primary float-right']
                ])        
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $codigo = $request->request->get('form')['codigo'];
            $idTurno = $this->decrypt($codigo); // Obtengo el ID del turno desencriptado

            if ($idTurno) {
                $turno = $turnoRepository->findById($idTurno);
                if ($turno) {
                    $session->set('escanerCodigo', 1);  // Marca que se encuentra activa la funcionalidad de escaneo de códigos 
                                                        // de turno y no la lista de turnos para retornar aquí luego de la edición

                    $logger->info(('Lee código de barras'),
                        [
                            'Turno ID' => $idTurno,
                            'Usuario' => $this->getUser()->getUsuario()
                        ]
                    );
                                                                                            
                    return $this->redirectToRoute('turno_edit', ['id' => $idTurno]);
                } else {
                    $error = 'No se localizó el turno';
                }    
            } else {
                $error = 'Código incorrecto';
            }
        }

        return $this->render('turno/code_scanner.html.twig', [
            'error' => $error,
            'form' => $form->createView(),
        ]);
    }


    /**
     * Alterna estado de Atendido (de No Atendido (1) a Atendido (2) o de Atendido (2) a No Atendido (1))
     * 
     * @Route("/turno/{id}/atendido", name="turno_atendido", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function atendido(Turno $turno, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        if ($turno->getEstado() == 1 || $turno->getEstado() == 2) {
            $turno->setEstado(($turno->getEstado() % 2) + 1);
            $this->getDoctrine()->getManager()->flush();
            $this->addFlash('success', ($turno->getEstado() == 2 ? $translator->trans('El turno') . ' se ha marcado como ' . $translator->trans('Atendido') : $translator->trans('El turno') . ' se ha marcado como ' . $translator->trans('Sin Atender')));
            $logger->info(($turno->getEstado() == 2 ? 'Marca como ' . $translator->trans('Atendido') : 'Marca como ' . $translator->trans('Sin Atender')),
                [
                    'Oficina' => $turno->getOficina()->getOficinayLocalidad(),
                    'Turno' => $turno->getTurno(),
                    'Solicitante' => $turno->getPersona()->getPersona() . ($turno->getPersona()->getOrganismo() ? ' | ' . $turno->getPersona()->getOrganismo() : ''),
                    'Usuario' => $this->getUser()->getUsuario()
                ]
            );
        }

        return $this->redirectToRoute('turno_index');

    }

    /**
     * Borra un turno
     * 
     * @Route("/turno/{id}", name="turno_delete", methods={"DELETE"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function delete(Request $request, Turno $turno, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete' . $turno->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($turno);
            $this->addFlash('danger', 'Se ha borrado el turno');
            $logger->info(
                'Turno Borrado',
                [
                    'Oficina' => $turno->getOficina()->getOficinayLocalidad(),
                    'Turno' => $turno->getTurno(),
                    'Usuario' => $this->getUser()->getUsuario()
                ]
            );
            $entityManager->flush();
        }

        return $this->redirectToRoute('turno_index');
    }

    /**
     * Marca un turno con estado de Ausente (No asistió)
     * Sólo turnos en estado sin Atender pueden ser pasados a Ausente
     * 
     * @Route("/turno/{id}/noAsistido", name="turno_no_asistido", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function no_asistido(Turno $turno, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        if ($turno->getEstado() == 1) { 
            $turno->setEstado(3);
            $this->getDoctrine()->getManager()->flush();
            $this->addFlash('success', $translator->trans('El turno') . ' se ha marcado como ' . $translator->trans('Ausente'));
            $logger->info(('Marca como ' . $translator->trans('Ausente')),
                [
                    'Oficina' => $turno->getOficina()->getOficinayLocalidad(),
                    'Turno' => $turno->getTurno(),
                    'Solicitante' => $turno->getPersona()->getPersona() . ($turno->getPersona()->getOrganismo() ? ' | ' . $turno->getPersona()->getOrganismo() : ''),
                    'Usuario' => $this->getUser()->getUsuario()
                ]
            );
        }

        return $this->redirectToRoute('turno_index');
        
    }

    /**
     * Rechaza un turno y opcionalmente envía un correo informando el motivo del rechazo
     * El turno una vez liberado podrá ser tomado por otra persona desde el Frontend
     * Sólo turnos en estado sin Atender pueden ser Rechazados
     * 
     * @Route("/turno/{id}/rechazado", name="turno_rechazado", methods={"GET","POST"})
     * 
     * @IsGranted("ROLE_EDITOR")
     */
    public function rechazado(Request $request, Turno $turno, MailerInterface $mailer, LoggerInterface $logger): Response
    {
        if ($turno->getEstado() == 1) {

            $motivoRechazo = $_ENV['MOTIVO_RECHAZO']; // Obtiene texto predeterminado desde variable de entorno

            $form = $this->createForm(TurnoRechazarType::class, $turno);
            $form->get('motivoRechazo')->setData($motivoRechazo);
            $form->get('enviarMail')->setData(false);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {

                $motivoRechazo = $request->request->get('turno_rechazar')['motivoRechazo'];

                // Envia correo notificando el Rechazo
                if (isset($request->request->get('turno_rechazar')['enviarMail'])) {

                    // Establece que plantilla de correo se utilizará en función al tipo de Sistema
                    $mailTemplate = 'turno/email_rechazado_turno_web.html.twig';
                    if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                        $mailTemplate = 'turno/email_rechazado_oralidad.html.twig';
                    }

                    $fromAdrress = $_ENV['MAIL_FROM'];
                    $email = (new TemplatedEmail())
                        ->from($fromAdrress)
                        ->to($turno->getPersona()->getEmail())
                        ->subject('Poder Judicial Santa Fe - Solicitud de Turno Cancelada')
                        ->htmlTemplate($mailTemplate)
                        ->context([
                            'expiration_date' => new \DateTime('+7 days'),
                            'turno' => $turno,
                            'motivoRechazo' => $motivoRechazo
                        ]);
                    $mailer->send($email);
                    $logger->info(
                        'Notificación de Rechazo Enviada',
                        [
                            'Destinatario' => $turno->getPersona()->getPersona(),
                            'Dirección' => $turno->getPersona()->getEmail(),
                            'Motivo Indicado' => $motivoRechazo
                        ]
                    );
                }

                $this->addFlash('warning', 'Se ha rechazado el turno');
                $logger->info(('Marca como Rechazado'),
                    [
                        'Oficina' => $turno->getOficina()->getOficinayLocalidad(),
                        'Turno' => $turno->getTurno(),
                        'Solicitante' => $turno->getPersona()->getPersona() . ($turno->getPersona()->getOrganismo() ? ' | ' . $turno->getPersona()->getOrganismo() : ''),
                        'Usuario' => $this->getUser()->getUsuario()
                    ]
                );

                // Almacena datos del rechazo
                $turnoRechazado = new TurnoRechazado();
                $turnoRechazado->setOficina($turno->getOficina());
                $turnoRechazado->setFechaHoraRechazo(new \DateTime(date("Y-m-d H:i:s")));
                $turnoRechazado->setFechaHoraTurno($turno->getFechaHora());
                $turnoRechazado->setMotivo($turno->getMotivo());
                if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                    $turnoRechazado->setNotebook($turno->getNotebook());
                    $turnoRechazado->setZoom($turno->getZoom());
                }
                $turnoRechazado->setPersona($turno->getPersona());
                $turnoRechazado->setEmailEnviado(isset($request->request->get('turno_rechazar')['enviarMail']));
                $turnoRechazado->setMotivoRechazo($motivoRechazo);

                // Libero el turno
                $turno->setEstado(1);
                $turno->setPersona(null);
                $turno->setMotivo('');
                if ($_ENV['SISTEMA_ORALIDAD_CIVIL']) {
                    $turno->setNotebook(false);
                    $turno->setZoom(false);
                }

                // Grabo
                $this->getDoctrine()->getManager()->persist($turnoRechazado);
                $this->getDoctrine()->getManager()->flush();

                return $this->redirectToRoute('turno_index');
            }

            return $this->render('turno/rechazar.html.twig', [
                'turno' => $turno,
                'form' => $form->createView(),
            ]);
        }
    }


    /**
     * Obtiene todas las localidades de una Circunscripcipón
     * 
     * @Route("/TurnosWeb/localidad_circunscripcion/{circunscripcion_id}", name="localidad_by_circunscripcion", requirements = {"circunscripcion_id" = "\d+"}, methods={"GET", "POST"})
     * 
     * @return string JSON con las localidades de una Circunscripción (Ej.: {{"id":25,"localidad":"Coronda"},....})
     */
    public function localidadByCircunscripcion($circunscripcion_id, LocalidadRepository $localidadRepository)
    {
        $localidades = $localidadRepository->findLocalidadesByCircunscripcion($circunscripcion_id);
        return new JsonResponse($localidades);
    }

    /**
     * Obtiene todas las localidades que tiene al menos una Oficina Habilitada en una Circunscripcipón
     * 
     * @Route("/TurnosWeb/localidades_habilitadas_circunscripcion/{circunscripcion_id}", name="localidadesHabilitadasByCircunscripcion", requirements = {"circunscripcion_id" = "\d+"}, methods={"GET", "POST"})
     * 
     * @return string JSON con las localidades de una Circunscripción (Ej.: {{"id":25,"localidad":"Coronda"},....})
     */
    public function localidadesHabilitadasByCircunscripcion($circunscripcion_id, OficinaRepository $oficinaRepository)
    {
        $localidades = $oficinaRepository->findByLocalidadesHabilitadasByCircunscripcion($circunscripcion_id);
        return new JsonResponse($localidades);
    }    


    /**
     * Obtiene todas las Oficinas habilitados de una Localidad
     * 
     * @Route("/TurnosWeb/oficina_localidad/{localidad_id}", name="oficinas_by_localidad", requirements = {"localidad_id" = "\d+"}, methods={"GET", "POST"})
     * 
     * @return string JSON con las Oficinas de una Localidad (Ej.: {{"id":37,"oficina":"Certificaciones - Turno Vespertino"},....})
     */
    public function oficinasByLocalidad($localidad_id, OficinaRepository $oficinaRepository)
    {
        $oficinas = $oficinaRepository->findOficinasHabilitadasByLocalidad($localidad_id);
        return new JsonResponse($oficinas);
    }


    /**
     * Obtiene una lista completa de Oficinas con información de su localidad
     * 
     * @Route("/TurnosWeb/oficinas", name="oficinas", methods={"GET", "POST"})
     * 
     * @return string JSON con la totalidad de Oficinas (Ej.: {{"id":37,"oficina":"Certificaciones - Turno Vespertino (Reconquista)"},....})
     */
    public function oficinas(OficinaRepository $oficinaRepository)
    {
        $oficinas = $oficinaRepository->findAllOficinas();
        return new JsonResponse($oficinas);
    }


    /**
     * Obtiene una lista completa de Organismos
     * 
     * @Route("/TurnosWeb/organismos", name="organismos", methods={"GET", "POST"})
     * 
     * @return string JSON con la totalidad de Organismos (Ej: {{"id":64,"organismo":"Juzg. 1ra.Instancia de Circuito (Casilda)"},...})
     */
    public function organismos(OrganismoRepository $organismoRepository)
    {
        $organismos = $organismoRepository->findAllOrganismos();
        return new JsonResponse($organismos);
    }


    /**
     * Obtiene todas las Oficinas del MP Civil
     * 
     * @Route("/TurnosWeb/oficinasMPCivil_localidad/{localidad_id}", name="oficinasMPCivil_localidad", requirements = {"localidad_id" = "\d+"}, methods={"GET", "POST"})
     * 
     * @return string JSON con las Oficinas del MP Civil de una Localidad
     */
    public function oficinasMPCivilByLocalidad($localidad_id = 2, OficinaRepository $oficinaRepository)        
    {
        $oficinas = [];
        if ($localidad_id == 2) {
            // ROSARIO
            $oficinas = [
                1 => 'Ministerio Pupilar',
                2 => 'Oficina de Gestión de las Defensorías Civiles',
                3 => [
                    3 => 'Defensoría Civil de Rosario N° 1',
                    4 => 'Defensoría Civil de Rosario N° 2',
                    5 => 'Defensoría Civil de Rosario N° 3',
                    6 => 'Defensoría Civil de Rosario N° 4',
                    7 => 'Defensoría Civil de Rosario N° 5',
                    8 => 'Defensoría Civil de Rosario N° 6',
                    9 => 'Defensoría Civil de Rosario N° 7',
                    10 => 'Defensoría Civil de Rosario N° 8',
                    11 => 'Defensoría Civil de Rosario N° 9',
                    12 => 'Defensoría Civil de Rosario N° 10',
                ]
            ];

            $oficinas = $oficinaRepository->findOficinasHabilitadasByLocalidadWithTelefono($localidad_id);
        }

        return new JsonResponse($oficinas);
    }


    /**
     * Obtiene los datos del último turno de una persona
     * 
     * Se la busca por el ID del Organismo Asociado. Por lo tanto sólo es útil en modo de operación SISTEMA_ORALIDAD_CIVIL
     * 
     * @Route("/TurnosWeb/datosUltimoTurnoPersona/{org_id}", name="datosUltimoTurnoPersona", requirements = {"org_id" = "\d+"}, methods={"GET", "POST"})
     * 
     * @return string JSON con los datos del último turno solicitado por una persona (Ej.: {{"apellido":"...","nombre":"...","email":"...","telefono":"..."},...})
     */
    public function datosUltimoTurnoPersona(TurnoRepository $turnoRepository, $org_id)
    {
        $datosUltimoTurno = $turnoRepository->findUltimoTurnoPersona($org_id);
        return new JsonResponse($datosUltimoTurno);
    }


    /**
     * Obtiene días (futuros) con turnos disponibles para una oficina en particular
     * 
     * @Route("/TurnosWeb/turnoslibres_oficina/{oficina_id}", name="turnoslibres_by_localidad", requirements = {"oficina_id" = "\d+"}, methods={"POST"})
     * 
     * @return string JSON con el siguiente formato: ["01/07/2020","02/07/2020", ....]
     */
    public function diasLibresByOficina(TurnoRepository $turnoRepository, $oficina_id)
    {
        $turnosLibres = $turnoRepository->findDiasDisponiblesByOficina($oficina_id);
        return new JsonResponse($turnosLibres);
    }


    /**
     * Este proceso recorre día a día el rango de días posibles de turnos para una oficina y retorna
     * un arreglo de los días que no tienen ningún turno libre o que no tienen turnos generados (feriados)
     * 
     * @Route("/TurnosWeb/diasOcupadosOficina/{oficina_id}", name="diasOcupadosOficina", requirements = {"oficina_id" = "\d+"}, methods={"GET", "POST"})
     */
    public function diasOcupadosByOficina(TurnoRepository $turnoRepository, $oficina_id)
    {
        if ($oficina_id != null) {
            // Obtiene el primer turno a partir del momento actual
            $primerDiaDisponible = $turnoRepository->findPrimerDiaDisponibleByOficina($oficina_id);

            // Obtiene el último turno disponible para la oficina
            $ultimoDiaDisponible = $turnoRepository->findUltimoDiaDisponibleByOficina($oficina_id);

            // Estable rangos temporales desde el primer día al último
            $desde = (new \DateTime)->createFromFormat('d/m/Y H:i:s', $primerDiaDisponible . '00:00:00');
            $hasta = (new \DateTime)->createFromFormat('d/m/Y H:i:s', $ultimoDiaDisponible . '23:59:59');

            // Recorre cada uno de los días y arma en $diasNoHabilitados los días que no tienen turnos libres
            // o bien, los turnos que no tienen turnos creados (feriados). Chequeo tambien que $desde y $hasta tengan valores
            $diasNoHabilitados = [];
            while (true && ($desde && $hasta)) {
                // Establece horaría máximo de búsqueda. Se busca desde las 0hs hasta las 23:59, día a día
                $horaHasta = (new \DateTime)->createFromFormat('d/m/Y H:i:s', $desde->format('d/m/Y') . ' 23:59:59');

                // OJO con este método. Debería retornar sólo si existen o no turnos y retorna todos los turnos.
                // TODO Mejorarlo por una cuestión de performance y de recursos
                $horarios = $turnoRepository->findExisteTurnoLibreByOficinaByFecha($oficina_id, $desde, $horaHasta);

                // Si no existen turnos libres para ese día (o bien, no existen turnos creados)
                if (!$horarios) {
                    // Lo almacena como día no habiltiado
                    $diasNoHabilitados[] = $desde->format('d/m/Y');
                }

                // Incrementa el intervalo en un día
                $desde->add(new DateInterval('P1D'));
                if ($desde >= $hasta) {
                    break;
                }
            }
        }

        return new JsonResponse($diasNoHabilitados);
    }


    /**
     * Obtiene los horarios disponibles para una fecha y oficina en particular
     * 
     * @Route("/TurnosWeb/horariosDisponiblesOficinaFecha/{oficina_id}/{fecha}", name="horarisDisponibles", methods={"POST"})
     */
    public function horariosDisponiblesByOficinaByFecha(TurnoRepository $turnoRepository, $oficina_id, $fecha)
    {
        $horariosDisponibles = $turnoRepository->findHorariosDisponiblesByOficinaByFecha($oficina_id, $fecha);
        return new JsonResponse($horariosDisponibles);
    }


    /**
     * Obtiene el porcentaje de la agenda que actualmente está ocupada para una Oficina en función a la disponibilidad futura de turnos de la misma
     * 
     * @Route("/TurnosWeb/ocupacionAgenda", name="ocupacionAgenda", methods={"GET", "POST"})
     */
    public function ocupacionAgenda(Request $request, TurnoRepository $turnoRepository)
    {
        $nivelOcupacionAgenda = 0;
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_AUDITORIA_GESTION')) {
            $filtroOficina = $request->request->get('oficina_id');
            $nivelOcupacionAgenda = $turnoRepository->findCantidadTurnosAsignados($filtroOficina) / $turnoRepository->findCantidadTurnosExistentes($filtroOficina);
        } else {
            if ($this->isGranted('ROLE_USER')) {
                // Busca en la oficina a la que pertenece el usuario
                $oficinaUsuario = $this->getUser()->getOficina()->getId();
                $nivelOcupacionAgenda = $turnoRepository->findCantidadTurnosAsignados($oficinaUsuario) / $turnoRepository->findCantidadTurnosExistentes($oficinaUsuario);
            }
        }

        return new JsonResponse(round($nivelOcupacionAgenda * 100));
    }


    /**
     * Obtiene rangos de fecha desde/hasta para :
     * 
     * @param int $momento
     * 
     *  1: Pasado   -> desde el 01/01/1970 al día anterior al actual
     *  2: Presente -> desde las 0hs a las 23:59 del día actual
     *  3: Futuro   -> del día posterior al día actual hasta el 31/12/2200
     */
    private function obtieneMomento($momento)
    {
        $rango = [];
        switch ($momento) {
            case 1: 
                $rango['desde'] = new \DateTime("1970-01-01 00:00:00");
                $rango['hasta'] = (new \DateTime(date("Y-m-d") . " 23:59:59"))
                    ->sub(new DateInterval('P1D')); // Resta un día al día actual
                break;
            case 2: 
                $rango['desde'] = new \DateTime(date("Y-m-d") . " 00:00:00");
                $rango['hasta'] = new \DateTime(date("Y-m-d") . " 23:59:59");
                break;
            case 3: 
                $rango['desde'] = (new \DateTime(date("Y-m-d") . " 00:00:00"))
                    ->add(new DateInterval('P1D')); // Suma un día al día actual
                $rango['hasta'] = new \DateTime("2200-12-31 23:59:59");
                break;
        }
        return $rango;
    }

    /**
     * Encripto información
     */
    private function encrypt($data): string
    {
        $method = "aes-256-cbc"; // Cipher Method
        $iv_length = openssl_cipher_iv_length($method); // Obtain Required IV length

        if (strlen($iv_length) > strlen($_ENV['APP_SECRET'])) {
            throw new Exception("ENV['APP_SECRET'] es demasiado corto para openssl_cipher_iv_length!");
        }

        $iv = substr($_ENV['APP_SECRET'],0, $iv_length);
        $pass = $_ENV['APP_SECRET']; 

        /* Base64 Encoded Encryption */
        $enc_data = base64_encode(openssl_encrypt($data, $method, $pass, true, $iv));

        return $enc_data;

    }

    /**
     * Desencripto información
     */
    private function decrypt($enc_data): string
    {
        $method = "aes-256-cbc"; // Cipher Method
        $iv_length = openssl_cipher_iv_length($method); // Obtain Required IV length

        if (strlen($iv_length) > strlen($_ENV['APP_SECRET'])) {
            throw new Exception("ENV['APP_SECRET'] es demasiado corto para openssl_cipher_iv_length!");
        }
              
        $iv = substr($_ENV['APP_SECRET'],0, $iv_length);
        $pass = $_ENV['APP_SECRET']; 
        
        /* Decode and Decrypt */
        $dec_data = openssl_decrypt(base64_decode($enc_data), $method, $pass, true, $iv);
        
        return $dec_data;
    }

}
