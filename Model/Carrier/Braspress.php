<?php
class Cammino_Braspress_Model_Carrier_Braspress extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface {

    protected $_code = 'camminobraspress';
    protected $_log = 'braspress.log';

    public function collectRates(Mage_Shipping_Model_Rate_Request $request) {
        if(!$this->isActive()) {
            return false;
        }

        $result = Mage::getModel("shipping/rate_result");

        /*  ==== CALCULA O PESO CUBADO =====, 
        que é a multiplicação das medidas de cada volume com sua quantidade e
        a densidade estabelecida como padrão por todas as transportadoras brasileiras.
        
        Se o PESO CUBADO for maior que o PESO REAL, o peso cubado é enviado para o web service calcular o frete.
        Caso contrário, o peso real é enviado.
        
        Esta é a diferença entre, por exemplo, carregar 10 kg de chumbo e 10 kg de pena.
        O espaço necessário para carregar o primeiro volume é muito menor que o requerido pelo segundo.  */

        $length_field_name = $this->getConfigData('length_field_name');
        $width_field_name = $this->getConfigData('width_field_name');
        $height_field_name = $this->getConfigData('height_field_name');

	    $has_length = ($attr = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $length_field_name)) && $attr->getId();
	    $has_width = ($attr = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $width_field_name)) && $attr->getId();
        $has_height = ($attr = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $height_field_name)) && $attr->getId();

        $calc_cubed_weight = $has_length && $has_width && $has_height;

        if($calc_cubed_weight):

            //  Verifica a densidade a ser utilizada, de acordo com o modal. Rodoviário = 300 Aéreo = 167
            $density = $this->getConfigData('modal_type') == 'R' ? 300 : 167;

            $cubed_weight = $real_weight = $total_weight = 0.00;
            
            foreach ($request->getAllItems() as $item):
                
                if ($item->getParentItem())
                    continue;

                $product = Mage::getSingleton('catalog/product')->load($item->getProduct()->getId());
                
                $product_length = $product->getData($length_field_name);
                $product_width = $product->getData($width_field_name);
                $product_height = $product->getData($height_field_name);

                // Se o produto não tiver alguma medida especificada, pega a medida padrão
                $length = floatval($product_length) > 0.0 ? $product_length : $this->getConfigData('default_length');
                $width = floatval($product_width) > 0.0 ? $product_width : $this->getConfigData('default_width');
                $height = floatval($product_height) > 0.0 ? $product_height : $this->getConfigData('default_height');

                /*  Calcula: comprimento * largura * altura, depois arredonda com duas casas decimais, 
                pois uma precisão maior afeta negativamente o valor do frete */
                $weight_cubed_volume = $this->measure($length) * $this->measure($width) * $this->measure($height);

                if($weight_cubed_volume > 0.00):
                    // Multiplica o valor acima com a densidade, obtendo o peso cubado do volume
                    $cubed_weight_volume = (float)number_format($weight_cubed_volume * $density, 3, '.', '');

                    //  Multiplica o peso cubado do volume com a quantidade, obtendo o peso cubado total do item
                    $cubed_weight += (float)number_format($cubed_weight_volume * $item->getQty(), 3, '.', '');
                else:
                    $cubed_weight = 0.00;
                    $calc_cubed_weight = false;
                endif;

                //  Multiplica o peso real do produto com a quantidade, obtendo o peso real do item
                $real_weight += (float)number_format($item->getWeight() * $item->getQty(), 3, '.', '');
            
            endforeach;

            //  Se o peso cubado é maior que o real, pega o cubado para enviar ao web service, caso contrário, pega o real
            $weight = $calc_cubed_weight && $cubed_weight > $real_weight ? $cubed_weight : $real_weight;

        else:
            $weight = $request->getPackageWeight();
        endif;

        //  Se a configuração do peso for em gramas, transforma o peso total para quilos
        if ($this->getConfigData('weight_type') == 1)
            $weight /= 1000;

        $package_value = number_format($request->getPackageValue(), 2, '.', '');        
        $origin_postcode = $this->clearPostcode(Mage::getStoreConfig("shipping/origin/postcode", $this->getStore()));
        $dest_postcode = $this->clearPostcode($request->getDestPostcode());

	    if ( $weight > 0 ):
            
            $service = $this->getShippingAmount($origin_postcode, $dest_postcode, $weight, $package_value, 1);
            
            
            if ($service):
                // verifica se precisa adicionar dias extras ao prazo de entrega
                if(intval($this->getConfigData("shippingdaysextra")) > 0):
                    $days = intval($service["days"]) + intval($this->getConfigData("shippingdaysextra"));
                else:
                    $days = intval($service["days"]);
                endif;

                // se houver uma regra para frete grátis, seta valor da transportadora para 0
                if ($request->getFreeShipping() === true)
                    $service['price'] = 0;
                
                $this->addRateResult($result, $service["price"], $this->shippingDays($days));
            else:
                $this->addError($result, "Desculpe, no momento não estamos atuando com entregas para sua região.");
            endif;
        else:
            $this->addError($result, "Informações inválidas para calcular o frete");
        endif;



        return $result;
    }
    
    private function addRateResult($result, $shippingPrice, $shippingDays) {
        $method = Mage::getModel("shipping/rate_result_method");
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData("title"));
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData("title") . " ($shippingDays) ");
        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);
        $result->append($method);
    }
    
    private function addError($result, $errorMessage) {
        $error = Mage::getModel("shipping/rate_result_error");
        $error->setCarrier($this->_code);
        $error->setCarrierTitle($this->getConfigData("title"));
        $error->setErrorMessage("$errorMessage");
        $result->append($error);
    }
    
    private function shippingDays($days) {
        if(intval($days) == 1)
            return "um dia útil";
        else
            return "$days dias úteis";
    }
    
    public function getShippingAmount($origin_postcode, $dest_postcode, $weight, $package_value, $qtd) {
        
        $cnpj = $this->clearCnpj($this->getConfigData('storecnpj'));
        $cnpj_origin = $cnpj;
        $cnpj_dest = $cnpj;
        $type = $this->getConfigData('shipping_type');
        $modal = $this->getConfigData('modal_type');

        $url = "http://www.braspress.com.br/cotacaoXml?param=";
        $url .= $cnpj.",";              # cnpj
        $url .= "2,";                   # emporigem 2 (fixo)
        $url .= $origin_postcode.",";   # cep origem
        $url .= $dest_postcode.",";     # cep destino
        $url .= $cnpj_origin.",";       # cnpj remetente
        $url .= $cnpj_dest.",";         # cnpj destinatario
        $url .= $type.",";              # tipo frete 1:CIF ou 2:FOB
        $url .= $weight.",";            # peso e cubagem 300 Kg/m³ ou peso aferido
        $url .= $package_value.",";     # valor da nota fiscal da carga
        $url .= $qtd.",";               # qtd volumes da carga
        $url .= $modal;                 # modal R:rodoviário A:aéreo

        if($this->getConfigData('debug')):
            $this->log("----- Braspress Cotação ------");
            $this->log("cnpj: ".$cnpj);
            $this->log("cep origem: ".$origin_postcode);
            $this->log("cep destino: ".$dest_postcode);
            $this->log("cnpj remetente: ".$cnpj_origin);
            $this->log("cnpj destinatario: ".$cnpj_dest);
            $this->log("tipo frete: ".$type);
            $this->log("peso: ".$weight);
            $this->log("valor: ".$package_value);
            $this->log("qtd volumes de carga: ".$qtd);
            $this->log("modal: ".$modal);
        endif;

        return $this->getXml($url);
    }

    public function getXml($url) {
        $content = utf8_encode(file_get_contents($url));
        $xml = simplexml_load_string($content);

        if($xml->MSGERRO == "OK"):
            return array (
                "days" => intval($xml->PRAZO),
                "price" => floatval(str_replace(",", ".", str_replace(".", "", $xml->TOTALFRETE)))
            );
        else:
            // as vezes o xml não estava parseando, então, tenta pegar o erro manualmente
            $e = explode("<erro>", $content);
            $e = explode("</erro>", $e[1]);
            $error = $e[0];
            $this->log("---- erro ao cotar o frete pela braspress -----");
            $this->log($error);
            return false;
        endif;
    }
    
    public function getAllowedMethods() {
        return array($this->_code => $this->getConfigData('title'));
    }

    private function measure($measure) {
        switch ($this->getConfigData('measure_type')) {
			case 'mm': $unit_measure = 0.001; break;
			case 'cm': $unit_measure = 0.01; break;
			case 'dm': $unit_measure = 0.1; break;
			case 'm':
			default: $unit_measure = 1; break;
        }
        return (float)number_format($measure * $unit_measure, 3, '.', '');
    }

    private function clearPostcode($postcode) {
        return str_replace('-', '', trim($postcode));
    }

    private function clearCnpj($cnpj) {
        $cnpj = str_replace('-', '', trim($cnpj));
        return str_replace('.', '', $cnpj);
    }

    public function log($message) {
        Mage::log($message, null, $this->_log);
    }
}