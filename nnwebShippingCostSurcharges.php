<?php

namespace nnwebShippingCostSurcharges;

use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;

class nnwebShippingCostSurcharges extends \Shopware\Components\Plugin {
	private $session = null;
	private $db = null;
	private $config = null;
	private $sSYSTEM;

	public static function getSubscribedEvents() {
		return [
			'Enlight_Controller_Action_PostDispatchSecure_Frontend_Checkout' => 'onCartAction',
            'Enlight_Controller_Action_PostDispatchSecure_Frontend_PremsOnePageCheckout' => 'onCartAction',
			'Shopware_Modules_Order_SendMail_FilterContext' => 'onSendMailFilterContext'
		];
	}

	public function activate(ActivateContext $context) {
		$context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
		parent::activate($context);
	}

	public function deactivate(DeactivateContext $context) {
		$context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
		parent::deactivate($context);
	}

	public function update(UpdateContext $context) {
		
		if (version_compare($context->getCurrentVersion(), "1.0.4", "<=")) {
			$service = $this->container->get('shopware_attribute.crud_service');
		
			$service->update('s_premium_dispatch_attributes', 'nnwebHideSurchargeIfZero', 'boolean', [
				'label' => 'Bei 0 verstecken', 
				'supportText' => 'Bei einem Wert von 0 wird die Position nicht seperat ausgegeben.', 
				'translatable' => true, 
				'displayInBackend' => true, 
				'position' => 2 
			]);
		
			$service->update('s_premium_dispatch_attributes', 'nnwebSurchargeLabel', 'string', [
				'position' => 3
			]);
			
			$this->deleteCacheAndGenerateModel();
		}
		
		$context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
		parent::update($context);
	}

	public function install(InstallContext $context) {
		$service = $this->container->get('shopware_attribute.crud_service');
		
		$service->update('s_premium_dispatch_attributes', 'nnwebShowSurcharge', 'boolean', [
			'label' => 'Position anzeigen', 
			'supportText' => 'Die Position wird separat im Warenkorb angezeigt.', 
			'translatable' => true, 
			'displayInBackend' => true, 
			'position' => 1 
		]);
		
		$service->update('s_premium_dispatch_attributes', 'nnwebHideSurchargeIfZero', 'boolean', [
			'label' => 'Bei 0 verstecken', 
			'supportText' => 'Bei einem Wert von 0 wird die Position nicht seperat ausgegeben.', 
			'translatable' => true, 
			'displayInBackend' => true, 
			'position' => 2 
		]);
		
		$service->update('s_premium_dispatch_attributes', 'nnwebSurchargeLabel', 'string', [
			'label' => 'Bezeichnung der Warenkorb-Position', 
			'supportText' => 'Diese Bezeichnung wird als Position unter den Versandkosten aufgeführt.', 
			'helpText' => 'Die Bezeichnung ist optional. Wird keine Bezeichnung angegeben, wird der Name ausgegeben, der in der Konfiguration der Versandkosten angegeben ist.', 
			'translatable' => true, 
			'displayInBackend' => true, 
			'position' => 3
		]);
		
		Shopware()->Db()->update('s_premium_dispatch_attributes', [
			'nnwebShowSurcharge' => 1 
		]);
		
		$this->deleteCacheAndGenerateModel();
	}

	public function uninstall(UninstallContext $context) {
		$service = $this->container->get('shopware_attribute.crud_service');
		$service->delete('s_premium_dispatch_attributes', 'nnwebShowSurcharge');
		$service->delete('s_premium_dispatch_attributes', 'nnwebSurchargeLabel');
		
		if (version_compare($context->getCurrentVersion(), "1.0.5", ">=")) {
			$service->delete('s_premium_dispatch_attributes', 'nnwebHideSurchargeIfZero');
		}
		
		$this->deleteCacheAndGenerateModel();
	}
	
	private function deleteCacheAndGenerateModel() {
		$metaDataCache = Shopware()->Models()->getConfiguration()->getMetadataCacheImpl();
		$metaDataCache->deleteAll();
		Shopware()->Models()->generateAttributeModels(['s_premium_dispatch_attributes']);
	}

	public function onSendMailFilterContext(\Enlight_Event_EventArgs $args) {
		$this->session = Shopware()->Session();
		$this->config = Shopware()->Config();
		
		$context = $args->getReturn();
		
		$return = $this->session->offsetGet('nnwebShippingCostSurcharges');
		
		$context['nnwebSurcharges'] = $return['nnSurcharges'];
		$context['sShippingCosts'] = $return['sShippingcosts'];
		
		return $context;
	}

	public function onCartAction(\Enlight_Controller_ActionEventArgs $args) {
		$this->session = Shopware()->Session();
		$this->db = Shopware()->Db();
		$this->config = Shopware()->Config();
		$this->sSYSTEM = Shopware()->System();
		
		$controller = $args->get('subject');
		$view = $controller->View();
		$sShippingcosts = $view->getAssign('sShippingcosts');
		
		$request = $args->get('request');
		
		if ($request->getActionName() == "finish") {
			$return = $this->session->offsetGet('nnwebShippingCostSurcharges');
			$this->session->offsetUnset('nnwebShippingCostSurcharges');
		} else {
			$return = $this->getVariables($sShippingcosts);
		}
		
		$this->session->offsetSet('nnwebShippingCostSurcharges', $return);
		
		$view->assign('nnwebSurcharges', $return['nnSurcharges']);
		$view->assign('sShippingcosts', $return['sShippingcosts']);
		
		$this->container->get('template')->addTemplateDir($this->getPath() . '/Resources/Views/');
	}

	private function getVariables($sShippingcosts) {
		$user = Shopware()->Modules()->Admin()->sGetUserData();
		
		$countryID = null;
		if (!empty($user['additional']['countryShipping']['id'])) {
			$countryID = (int) $user['additional']['countryShipping']['id'];
		}

		$basket = Shopware()->Modules()->Admin()->sGetDispatchBasket($countryID);
		$surcharges = $this->sGetPremiumDispatchSurcharge($basket);
		$nnSurcharges = array();

		$surcharges = $this->container->get('events')->filter(
			'nnwebShippingCostSurcharges_filterSurcharges',
			$surcharges,
			new \Enlight_Event_EventArgs(['subject' => $this])
		);

		if (!empty($sShippingcosts)) {
			foreach ($surcharges as $dispatch) {
				if (!empty($dispatch['nnwebshowsurcharge'])) {
					// Determinate tax automatically
					$taxAutoMode = $this->config->get('sTAXAUTOMODE');
					if (!empty($taxAutoMode)) {
						$discount_tax = $basket['max_tax'];
					} else {
						$discount_tax = $this->config->get('sDISCOUNTTAX');
						$discount_tax = empty($discount_tax) ? 0 : (float) str_replace(',', '.', $discount_tax);
					}

					if (empty($dispatch['calculation'])) {
						$from = round($basket['weight'], 3);
					} elseif ($dispatch['calculation'] == 1) {
						if (
							($this->config->get('sARTICLESOUTPUTNETTO') && !$this->sSYSTEM->sUSERGROUPDATA['tax']) ||
							(!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
						) {
							$from = round($basket['amount_net'], 2);
						} else {
							$from = round($basket['amount'], 2);
						}
					} elseif ($dispatch['calculation'] == 2) {
						$from = round($basket['count_article']);
					} elseif ($dispatch['calculation'] == 3) {
						$from = round($basket['calculation_value_' . $dispatch['id']]);
					} else {
						continue;
					}
					$result = $this->db->fetchRow(
						'SELECT `value` , factor, name
						FROM s_premium_shippingcosts ps
						LEFT JOIN s_premium_dispatch pd ON ps.dispatchID = pd.id
						WHERE `from` <= ?
						AND dispatchID = ?
						ORDER BY `from` DESC
						LIMIT 1',
						[
							$from,
							$dispatch['id']
						]
					);

					if ($result === false) {
						continue;
					}

					$surchargeValue = $result['value'];
					if (!empty($result['factor'])) {
						$surchargeValue += $result['factor'] / 100 * $from;
					}

					if (!empty($dispatch['nnwebhidesurchargeifzero']) && (empty($surchargeValue) || $surchargeValue == "0.00")) {
						continue;
					}

					if (!empty($dispatch['tax_calculation'])) {
						$context = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext();
						$taxRule = $context->getTaxRule($dispatch['tax_calculation']);
						$discount_tax = $taxRule->getTax();
					}

					if (empty($this->sSYSTEM->sUSERGROUPDATA['tax']) && !empty($this->sSYSTEM->sUSERGROUPDATA['id'])) {
						$surchargeValue = round($surchargeValue * 100 / (100 + $discount_tax), 2);
					}

					$value = $this->getTranslation($dispatch["id"]);
					if (empty($value)) {
						$value = (!empty($dispatch["nnwebsurchargelabel"])) ? $dispatch["nnwebsurchargelabel"] : $result['name'];
					}

					if ($sShippingcosts - $surchargeValue >= 0) {
						$sShippingcosts -= $surchargeValue;
					}

					$nnSurcharges[] = [
						"label" => $value,
						"value" => $surchargeValue
					];
				}
			}
		}

		return array(
			'nnSurcharges' => $nnSurcharges,
			'sShippingcosts' => $sShippingcosts
		);
	}
	

	public function sGetPremiumDispatchSurcharge($basket, $type = 2) {
		if (empty($basket)) {
			return false;
		}
		$type = (int) $type;
		
		$statements = $this->db->fetchPairs('
                SELECT id, bind_sql
                FROM s_premium_dispatch
                WHERE active = 1 AND type = ?
                AND bind_sql IS NOT NULL
            ', [
			$type 
		]);
		
		$sql_where = '';
		foreach ($statements as $dispatchID => $statement) {
			$sql_where .= "
			AND ( d.id!=$dispatchID OR ($statement))
			";
		}
		$sql_basket = [];
		foreach ($basket as $key => $value) {
			$sql_basket[] = $this->db->quote($value) . " as `$key`";
		}
		$sql_basket = implode(', ', $sql_basket);
		
		$sql = "
		SELECT d.id, d.calculation, d.tax_calculation, da.nnwebshowsurcharge, da.nnwebsurchargelabel, da.nnwebhidesurchargeifzero
		FROM s_premium_dispatch d
	
		JOIN ( SELECT $sql_basket ) b
		JOIN s_premium_dispatch_countries dc
		ON d.id = dc.dispatchID
		AND dc.countryID=b.countryID
		JOIN s_premium_dispatch_paymentmeans dp
		ON d.id = dp.dispatchID
		AND dp.paymentID=b.paymentID
		LEFT JOIN s_premium_holidays h
		ON h.date = CURDATE()
		LEFT JOIN s_premium_dispatch_attributes da
		ON d.id=da.dispatchID
		LEFT JOIN s_premium_dispatch_holidays dh
		ON d.id=dh.dispatchID
		AND h.id=dh.holidayID
	
		LEFT JOIN (
		SELECT dc.dispatchID
		FROM s_order_basket b
		JOIN s_articles_categories_ro ac
		ON ac.articleID=b.articleID
		JOIN s_premium_dispatch_categories dc
		ON dc.categoryID=ac.categoryID
		WHERE b.modus=0
		AND b.sessionID='{$this->session->offsetGet('sessionId')}'
		GROUP BY dc.dispatchID
		) as dk
		ON dk.dispatchID=d.id
	
		LEFT JOIN s_user u
		ON u.id=b.userID
		AND u.active=1
	
		LEFT JOIN s_user_addresses as ub
		ON ub.user_id = u.id
		AND ub.id = :billingAddressId
	
		LEFT JOIN s_user_addresses as us
		ON us.user_id = u.id
		AND us.id = :shippingAddressId
	
		WHERE d.active=1
		AND (
		(bind_time_from IS NULL AND bind_time_to IS NULL)
		OR
		(IFNULL(bind_time_from,0) <= IFNULL(bind_time_to,86400) AND TIME_TO_SEC(DATE_FORMAT(NOW(),'%H:%i:00')) BETWEEN IFNULL(bind_time_from,0) AND IFNULL(bind_time_to,86400))
		OR
		(bind_time_from > bind_time_to AND TIME_TO_SEC(DATE_FORMAT(NOW(),'%H:%i:00')) NOT BETWEEN bind_time_to AND bind_time_from)
		)
		AND (
		(bind_weekday_from IS NULL AND bind_weekday_to IS NULL)
		OR
		(IFNULL(bind_weekday_from,1) <= IFNULL(bind_weekday_to,7) AND REPLACE(WEEKDAY(NOW()),0,6)+1 BETWEEN IFNULL(bind_weekday_from,1) AND IFNULL(bind_weekday_to,7))
		OR
		(bind_weekday_from > bind_weekday_to AND REPLACE(WEEKDAY(NOW()),0,6)+1 NOT BETWEEN bind_weekday_to AND bind_weekday_from)
		)
		AND (bind_weight_from IS NULL OR bind_weight_from <= b.weight)
		AND (bind_weight_to IS NULL OR bind_weight_to >= b.weight)
		AND (bind_price_from IS NULL OR bind_price_from <= b.amount)
		AND (bind_price_to IS NULL OR bind_price_to >= b.amount)
		AND (bind_instock=0 OR bind_instock IS NULL OR (bind_instock=1 AND b.instock) OR (bind_instock=2 AND b.stockmin))
		AND (bind_laststock=0 OR (bind_laststock=1 AND b.laststock))
		AND (bind_shippingfree=2 OR NOT b.shippingfree)
		AND dh.holidayID IS NULL
		AND (d.multishopID IS NULL OR d.multishopID=b.multishopID)
		AND (d.customergroupID IS NULL OR d.customergroupID=b.customergroupID)
		AND dk.dispatchID IS NULL
		AND d.type = $type
		AND (d.shippingfree IS NULL OR d.shippingfree > b.amount)
		$sql_where
		GROUP BY d.id
		";
		
		return $this->db->fetchAll($sql, [
			'billingAddressId' => $this->getBillingAddressId(), 
			'shippingAddressId' => $this->getShippingAddressId() 
		]);
	}

	private function getBillingAddressId() {
		if ($this->session->offsetGet('checkoutBillingAddressId')) {
			return (int) $this->session->offsetGet('checkoutBillingAddressId');
		}
		if (!$this->session->offsetGet('sUserId')) {
			return 0;
		}
		$dbal = Shopware()->Container()->get('dbal_connection');
		
		return (int) $dbal->fetchColumn('
            SELECT default_billing_address_id
            FROM s_user WHERE id = :id
            ', [
			'id' => $this->session->offsetGet('sUserId') 
		]);
	}

	private function getShippingAddressId() {
		if ($this->session->offsetGet('checkoutShippingAddressId')) {
			return (int) $this->session->offsetGet('checkoutShippingAddressId');
		}
		if (!$this->session->offsetGet('sUserId')) {
			return 0;
		}
		$dbal = Shopware()->Container()->get('dbal_connection');
		
		return (int) $dbal->fetchColumn('
            SELECT default_shipping_address_id
            FROM s_user WHERE id = :id
            ', [
			'id' => $this->session->offsetGet('sUserId') 
		]);
	}
	
	private function getTranslation($dispatchId) {
		$translationComponent = Shopware()->Container()->get('translation');
		
		$contextService = Shopware()->Container()->get('shopware_storefront.context_service');
		$languageId = $contextService->getShopContext()->getShop()->getId();
        
        $translationData = $translationComponent->readBatch($languageId, 's_premium_dispatch_attributes', $dispatchId);
        if (!empty($translationData[0]["objectdata"]["__attribute_nnwebsurchargelabel"]))
        	return $translationData[0]["objectdata"]["__attribute_nnwebsurchargelabel"];
        return "";
	}
}
