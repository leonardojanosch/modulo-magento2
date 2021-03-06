<?php

namespace Ids\Andreani\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Ids\Andreani\Helper\Data as AndreaniHelper;
use Magento\Directory\Model\Region;
use SoapClient;
use SoapFault;

/**
 * Class Webservice
 *
 * @description Clase que interactua con el WS de Andreani.
 * @author Jhonattan Campo <jcampo@ids.net.ar>
 * @package Ids\Andreani\Model
 */
class Webservice extends WsseAuthHeader
{
    const MODE_DEV       = 'dev';
    const MODE_PROD      = 'prod';

    /**
     * @var
     */
    protected $_wssNs;

    /**
     * @var
     */
    protected $_ns;

    /**
     * @var
     */
    protected $_user;

    /**
     * @var
     */
    protected $_pass;

    /**
     * @var
     */
    protected $_sucursalContrato;

    /**
     * @var
     */
    protected $_urgenteContrato;

    /**
     * @var
     */
    protected $_estandarContrato;

    /**
     * @var
     */
    protected $_cliente;

    /**
     * @var
     */
    protected $_auth;

    /**
     * @var
     */
    protected $_options;

    /**
     * @var Checkout
     */
    protected $_checkoutSession;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var AndreaniHelper
     */
    protected $_andreaniHelper;

    /**
     * @var
     */
    protected $_code;

    /**
     * @var
     */
    protected $_distanciaFinalTxt;

    /**
     * @var
     */
    protected $_duracionFinal;

    /**
     * @var
     */
    protected $_mode;

    /**
     * @var
     */
    protected $_envio;

    /**
     * @var
     */
    protected $_dataGuia;

    /**
     * @var Region
     */
    protected $_region;

    /**
     * Webservice constructor.
     * @param AndreaniHelper $andreaniHelper
     * @param LoggerInterface $logger
     * @param CheckoutSession $checkoutSession
     * @param Region $region
     */
    public function __construct(
        AndreaniHelper $andreaniHelper,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession,
        Region $region
    )
    {
        $this->_andreaniHelper      = $andreaniHelper;
        $this->_logger              = $logger;
        $this->_user                = utf8_encode($this->_andreaniHelper->getUsuario());
        $this->_pass                = utf8_encode($this->_andreaniHelper->getPass());
        $this->_sucursalContrato    = utf8_encode($this->_andreaniHelper->getSucursalContrato());
        $this->_urgenteContrato     = utf8_encode($this->_andreaniHelper->getUrgenteContrato());
        $this->_estandarContrato    = utf8_encode($this->_andreaniHelper->getEstandarContrato());
        $this->_cliente             = utf8_encode($this->_andreaniHelper->getNroCliente());
        $this->_dataGuia            = [];
        $this->_options =
            [
                'soap_version' => SOAP_1_2,
                'exceptions' => true,
                'trace' => 1,
                'wdsl_local_copy' => false
            ];

        $this->_checkoutSession = $checkoutSession;
        $this->_region = $region;

        parent::__construct($this->_user, $this->_pass);
    }

    /**
     * @param $carrier
     * @return string
     */
    public function getContrato($carrier)
    {
        switch($carrier)
        {
            case 'andreaniestandar' :
                $contrato = $this->_estandarContrato;
                break;
            case 'andreaniurgente' :
                $contrato = $this->_urgenteContrato;
                break;
            case 'andreanisucursal' :
                $contrato = $this->_sucursalContrato;
                break;
            default:
                $contrato = '';
                break;
        }

        return $contrato;
    }

    /**
     * @param $params
     * @param $carrier
     * @return float
     */
    public function cotizarEnvio($params,$carrier)
    {
        $helper  = $this->_andreaniHelper;

        try
        {
            if($helper->getModo() == $helper::ENVMODTEST)
            {
                $urlCotizar  = $helper->getWSMethodUrl($helper::COTIZACION,$helper::ENVMODTEST);
                $soapVersion = $helper->getSoapVersion($helper::COTIZACION,$helper::ENVMODTEST);
            }
            else
            {
                $urlCotizar  = $helper->getWSMethodUrl($helper::COTIZACION,$helper::ENVMODPROD);
                $soapVersion = $helper->getSoapVersion($helper::COTIZACION,$helper::ENVMODPROD);
            }

            $this->_options['soap_version'] = constant($soapVersion);
            $client = new SoapClient($urlCotizar, $this->_options);
            $client->__setSoapHeaders([$this]);

            $sucursalRetiro     = ['sucursalRetiro' => ''];
            $params = array_merge($sucursalRetiro, $params);

            $phpresponse = $client->CotizarEnvio(
            [
                'cotizacionEnvio'   =>
                [
                    'CPDestino'     => $params['cpDestino'],
                    'Cliente'       => $this->_cliente,
                    'Contrato'      => $this->getContrato($carrier),
                    'Peso'          => intval($params['peso']),
                    'SucursalRetiro'=> $params['sucursalRetiro'],
                    'ValorDeclarado'=> intval($params['valorDeclarado']),
                    'Volumen'       => intval($params['volumen']),
                ]
            ]);

            $costoEnvio  = floatval($phpresponse->CotizarEnvioResult->Tarifa);

            return $costoEnvio;

        } catch (SoapFault $e) {
            if($helper->getDebugHabilitado())
                $helper->log($e,'erorres_andreani_webservice_'.date('Y_m').'.log');
            else
                $helper->log($e->getMessage(),'erorres_andreani_webservice_'.date('Y_m').'.log');
        }
    }

    /**
     * @param $params
     * @param $carrier
     * @return array
     */
    public function GenerarEnviosDeEntregaYRetiroConDatosDeImpresion($params,$carrier)
    {
        $helper     = $this->_andreaniHelper;
        $contrato   = '';
        $dataGuia   = $this->_dataGuia;

        switch($carrier)
        {
            case 'andreaniestandar':
                $contrato   = $this->_estandarContrato;
                break;
            case 'andreaniurgente':
                $contrato   = $this->_urgenteContrato;
                break;
            case 'andreanisucursal':
                $contrato   = $this->_sucursalContrato;
                break;
            default:
                break;
        }
        try
        {
            if($helper->getModo() == $helper::ENVMODTEST)
            {
                $urlGenerarEnvio  = $helper->getWSMethodUrl($helper::GENENVIOENTREGARETIROIMPRESION,$helper::ENVMODTEST);
                $soapVersion      = $helper->getSoapVersion($helper::GENENVIOENTREGARETIROIMPRESION,$helper::ENVMODTEST);
            }
            else
            {
                $urlGenerarEnvio  = $helper->getWSMethodUrl($helper::GENENVIOENTREGARETIROIMPRESION,$helper::ENVMODPROD);
                $soapVersion      = $helper->getSoapVersion($helper::GENENVIOENTREGARETIROIMPRESION,$helper::ENVMODPROD);
            }

            $this->_options['soap_version'] = constant($soapVersion);
            $client = new SoapClient($urlGenerarEnvio, $this->_options);
            $client->__setSoapHeaders([$this]);

            $provincia = $this->_region->loadByCode($params['provincia'],'AR');
            $params['provincia'] = $provincia->hasData() ? $provincia->getName() : $params['provincia'];

            $phpresponse = $client->GenerarEnviosDeEntregaYRetiroConDatosDeImpresion(
                [
                    'parametros'   =>
                        [
                            'Provincia'                     => $params['provincia'],
                            'Localidad'                     => $params['localidad'],
                            'CodigoPostal'                  => $params['codigopostal'],
                            'Calle'                         => $params['calle'],
                            'Numero'                        => $params['numero'],
                            'Piso'                          => $params['piso'],
                            'Departamento'                  => $params['departamento'],
                            'Nombre'                        => $params['nombre'],
                            'Apellido'                      => $params['apellido'],
                            'NombreAlternativo'             => $params['nombrealternativo'],
                            'ApellidoAlternativo'           => $params['apellidoalternativo'],
                            'TipoDeDocumento'               => $params['tipodedocumento'],
                            'NumeroDeDocumento'             => $params['numerodedocumento'],
                            'Email'                         => $params['email'],
                            'TelefonoFijo'                  => $params['telefonofijo'],
                            'TelefonoCelular'               => $params['telefonocelular'],
                            'CategoriaPeso'                 => $params['categoriapeso'],
                            'Peso'                          => $params['peso'],
                            'DetalleDeProductosAEntregar'   => $params['detalledeproductosaentregar'],
                            'DetalleDeProductosARetirar'    => $params['detalledeproductosaretirar'],
                            'Volumen'                       => $params['volumen'],
                            'ValorDeclaradoConIva'          => $params['valordeclaradoconiva'],
                            'Contrato'                      => $contrato,
                            'IdCliente'                     => $params['idcliente'],
                            'SucursalDeRetiro'              => $params['sucursalderetiro'],
                            'NumeroTransaccion'             => $params['increment_id']
                        ]
                ]);

            if(isset($phpresponse->GenerarEnviosDeEntregaYRetiroConDatosDeImpresionResult->CodigoDeResultado))
            {
                $dataGuia['datosguia'] 		= $phpresponse;
                $dataGuia['lastrequest'] 	= $params;

                if($helper->getDebugHabilitado())
                {
                    $helper->log(print_r($params,true),'generacion_guia_andreani_'.date('Y_m').'.log');
                    $helper->log(print_r($phpresponse,true),'generacion_guia_andreani_'.date('Y_m').'.log');
                }

                return $dataGuia;
            }
            else
            {
                return $phpresponse->GenerarEnviosDeEntregaYRetiroConDatosDeImpresionResult->CodigoDeResultado;
            }

        } catch (SoapFault $e){
            if($helper->getDebugHabilitado())
                $helper->log($e,'erorres_andreani_webservice_'.date('Y_m').'.log');
            else
                $helper->log($e->getMessage(),'erorres_andreani_webservice_'.date('Y_m').'.log');
        }
    }

    /**
     * Trae las sucursales de Andreani segun los parámetros
     *
     * @param $params
     * @return \stdClass | array
     */
    public function consultarSucursales(array $params = [])
    {
        /** @var $helper \Ids\Andreani\Helper\Data */
        $helper     = $this->_andreaniHelper;
        $response   = [];

        $urlSucursales = $helper->getWSMethodUrl($helper::SUCURSALES,self::MODE_DEV);

        try
        {
            $client = new SoapClient($urlSucursales, $this->_options);
            $client->__setSoapHeaders([$this]);

            $phpresponse = $client->ConsultarSucursales(
            [
                'consulta' =>
                    [
                        'CodigoPostal'  =>  isset($params['cpDestino'])?$params['cpDestino']:null,
                        'Localidad'     =>  isset($params['localidad'])?$params['localidad']:null,
                        'Provincia'     =>  isset($params['provincia'])?$params['provincia']:null,
                    ]
            ]);

            if(isset($phpresponse->ConsultarSucursalesResult->ResultadoConsultarSucursales))
            {
                $response = $phpresponse->ConsultarSucursalesResult->ResultadoConsultarSucursales;
            }

            return $response;

        } catch (SoapFault $e) {
            if($helper->getDebugHabilitado())
                $helper->log($e,'erorres_andreani_webservice_'.date('Y_m').'.log');
            else
                $helper->log($e->getMessage(),'erorres_andreani_webservice_'.date('Y_m').'.log');
        }
    }

}