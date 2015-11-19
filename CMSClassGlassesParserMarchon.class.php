<?php

class CMSClassGlassesParserMarchon extends CMSClassGlassesParser {
    private $countAllItem = 0;
    private $countAllVariation = 0;
    private $countAllVariationInStock = 0;
    private $countItem = 0;
    private $countVariation = 0;
    private $countVariationInStock = 0;

    const URL_HOME = "https://account.mymarchon.com/bpm/AccountHome/";
    const URL_LOGIN = "https://account.mymarchon.com/pkmslogin.form?token=Unknown";
    const URL_BRANDS = "https://account.mymarchon.com/bpm/Module_GETBRANDSFORUSRWeb/brandlist/invoke";
    const URL_AS = "https://account.mymarchon.com/bpm/MVP_AssetsWeb/MVP_AssetList/assetList";
    const URL_CAT = "https://account.mymarchon.com/bpm/ProductCatologWebWeb/catalog/catalog";
    const URL_PRODUCT = "https://account.mymarchon.com/bpm/ProductCatologWebWeb/GetSpecSheetInfoJson/getSpecSheetByStyle";
    const URL_IMG = "https://account.mymarchon.com/Images/jpg_L/";

    const ACCOUNT_NUMBER = "0001510964";
    const SALES_ORG = "2010";
    const USER_ID = "1510964";
    const DIST_CHANNEL = "10";

    public function getProviderId() {
        return CMSLogicProvider::MARCHON;
    }

    public function doLogin() {
        $http = $this->getHttp1();

        $http->doGet(self::URL_HOME);

        $post = array(
            'username' => $this->getUsername(),
            'Password' => $this->getPassword(),
            'login-form-type' => 'pwd',
        );

        $http->doPost(self::URL_LOGIN, $post);

        $http->doGet(self::URL_HOME);
    }

    /**
     * @return CMSPluginHttp
     * это осталось еще из предыдущего
     */
    public function getHttp1() {
        $this->getHttp();
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_FOLLOWLOCATION, false);

        curl_setopt($this->getHttp()->getCurl(), CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_CAINFO, CMSConfig::get('plugin_http::ssl_sertificate_marchon'));
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_VERBOSE, true);
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_CERTINFO, true);

        return $this->getHttp();
    }

    public function isLoggedIn($contents) {
        return stripos($contents, "/bpm/AccountHome/") !== false;
    }


    /**
     * Возвращает все бренды которые есть на сайте
     * @return array
     * @throws Exception не получены бренды
     */
    private function getBrands() {
        $http = $this->getHttp();

        $brandPost = array(
            "accountNumber" => self::ACCOUNT_NUMBER,
            "salesOrg" => self::SALES_ORG,
        );

        $http->doJsonPost(self::URL_BRANDS, json_encode($brandPost));
        $content = $http->getContents(false);

        $brands = json_decode($content, true);

        if(!$brands['brands']) {
            throw new Exception("Where are brands?");
        }

        return $brands['brands'];
    }


    /**
     * Синхронизация брендов на сайте с брендами в базе
     */
    public function doSyncBrands() {
        $brands = $this->getBrands();

        $myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        $coded = array();

        foreach($myBrands as $b) {
            if($b instanceof CMSTableBrand) {
                $coded[$b->getCode()] = $b;
            }
        }

        foreach($brands as $key => $brand) {
            if(!isset($coded[$brand['brandCode']])) {
                echo "Create brand {$brand['brandName']}.\n";
                CMSLogicBrand::getInstance()->create($this->getProvider(), $brand['brandName'], $brand['brandCode'], '');
            } else {
                echo "Brand {$brand['brandName']} already isset.\n";
            }
        }
    }

    /**
     * Возвращает товары бренда
     * @param $brandCode
     * @return array
     */
    private function getBrandItems($brandCode) {
        $http = $this->getHttp();

        $brandPost = array(
            "soldTo" => self::ACCOUNT_NUMBER,
            "salesOrg" => self::SALES_ORG,
            "distChannel" => self::DIST_CHANNEL,
        );

        $brandPost["brandCode"] = $brandCode;

        $http->doJsonPost(self::URL_CAT, json_encode($brandPost));
        $content = $http->getContents(false);

        $items = json_decode($content, true);

        $items = $items['catalog']['catalogStyle'];

        return $items;
    }


    /**
     * Возвращает информацию о модели (вариации)
     * @param $itemStyle код модели
     * @return array
     */
    private function getItem($itemStyle) {
        $http = $this->getHttp();

        $itemPost = array(
            "itemType" => "F",
            "orderType" => "ZRX",
            "salesOrg" => self::SALES_ORG,
            "distChannel" => self::DIST_CHANNEL,
            "userCredential" => array(
                "userID" => self::USER_ID,
                "salesOrg" => self::SALES_ORG,
                "userType" => "MVP_PRINCIPAL",
                "name" => "EYELAND VISION",
                "language" => "en_US",
                "phoneExtension" => "null",
                "premierStatus" => "",
                "accountNumber" => self::ACCOUNT_NUMBER,
            ),
        );

        $itemPost["style"] = $itemStyle;

        $http->doJsonPost(self::URL_PRODUCT, json_encode($itemPost));
        $content = $http->getContents(false);

        $item = json_decode($content, true);

        if(!isset($item['skuDetail'])) {
            return;
        }

        $itemData = $item['skuDetail'];

        return $itemData;
    }


    /**
     * Парсинг товаров на странице категорий
     */
    public function doSyncItems() {
        echo "\n--Marshon Sync Items start\n";

        $brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach($brands as $brand) {
            if($brand instanceof CMSTableBrand) {
                $this->countItem = 0;
                $this->countVariation = 0;
                $this->countVariationInStock = 0;

                if ($brand->getValid()) {
                    echo '----', get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(),' code - ' . $brand->getCode() ,"\n";
                } else {
                    echo '----', get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                    continue;
                }

                // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
                echo "------Set is_valid flag in 0 for {$brand->getTitle()}\n";
                $this->resetModelByBrand($brand);

                // Сбрасываем сток для бренда
                echo "------Set detail_stock_count flag in 0 for {$brand->getTitle()} details\n";
                $this->resetStockByBrand($brand);

                $items = $this->getBrandItems($brand->getCode());

                foreach($items as $key => $item) {
                    $this->parseItem($brand, $item['styleName'], $item['style']);
                }
                echo "\n--------" . $brand->getTitle() . "\n";
                echo "----------Count item {$this->countItem}\n";
                echo "----------Count variations {$this->countVariation}\n";
                echo "----------In stock count variations {$this->countVariationInStock}\n";
                echo "===============================================================\n\n";
            }
        }
        echo "\n---All count item {$this->countAllItem}\n";
        echo "---All count variations {$this->countAllVariation}\n";
        echo "---All in stock count variations {$this->countAllVariationInStock}\n";
    }


    /**
     * Парсинг страницы товара
     * @param CMSTableBrand $brand
     * @param $itemName
     * @param $itemStyle
     */
    private function parseItem(CMSTableBrand $brand, $itemName, $itemStyle) {
        echo "--------Sync item {$itemName}, code - {$itemStyle}.\n";
        $result = array();

        $variations = $this->getItem($itemStyle);

        if(!$variations) {
            echo "----------Where are variations?!\n";
            return;
        }

        // счетчики
        $this->countItem++;
        $this->countAllItem++;

        foreach($variations as $key => $variation) {
            $stock = 0;
            $this->countVariation++;
            $this->countAllVariation++;

            if($variation['stockStatus'] == "Available") {
                $stock = 1;
            } else {
                echo "\n----------variation {$variation['color']} ({$variation['colorDescription']}) not in stock. (not parse!)\n";
                echo "==================================================================\n";
                continue;
            }

            $this->countVariationInStock++;
            $this->countAllVariationInStock++;

            $itemImg = self::URL_IMG . $variation['colorImage'];

            // определяем тип очков
            if(stripos($variation['marketingGroupDescription'], "SUN") !== false) {
                $typeItem = CMSLogicGlassesItemType::getInstance()->getSun();
            } else {
                $typeItem = CMSLogicGlassesItemType::getInstance()->getEye();
            }

            // убираем из названия цвета код цвета
            $pattern = "/\(". $variation['color'] ."\)/";
            $variation['colorDescription'] = trim(preg_replace($pattern, '', $variation['colorDescription']));

            preg_match("/(\d{2})(\d{2})/", $variation['size'], $matches);

            $size1 = isset($matches[1]) ? $matches[1] : 0;
            $size2 = isset($matches[2]) ? $matches[2] : 0;

            // иногда есть размеры формата 0xxx
            if($size1[0] == "0" && $variation['SSA']) {
                $size1 = $variation['SSA'];
                $size2 = $variation['SSDBL'];
            }

            $upc = trim($variation['upcNumber']);

            // if(strlen($upc) < 13) {
            //     $upc = "0" . $upc;
            // }

            echo "\n";
            echo "----------brand         - {$brand->getTitle()}\n";
            echo "----------model_name    - {$itemName}\n";
            echo "----------external_id   - {$itemStyle}\n";
            echo "----------color_title   - {$variation['colorDescription']}\n";
            echo "----------color_code    - {$variation['color']}\n";
            echo "----------size 1        - {$size1}\n";
            echo "----------size 2        - {$size2}\n";
            echo "----------size 3        - {$variation['templeLength']}\n";
            echo "----------image         - {$itemImg}\n";
            echo "----------price         - {$variation['retail']}\n";
            echo "----------type          - {$variation['marketingGroupDescription']}\n";
            echo "----------stock         - {$stock}\n";
            echo "----------upc           - {$upc}\n";
            echo "--------------------------------------------\n";

            // создаем обьект модели и синхронизируем
            $item = new CMSClassGlassesParserItem();
            $item->setBrand($brand);
            $item->setTitle($itemName);
            $item->setExternalId($itemStyle);
            $item->setType($typeItem);
            $item->setColor($variation['colorDescription']);
            $item->setColorCode(trim($variation['color']));
            $item->setStockCount($stock);
            $item->setPrice(trim($variation['retail']));
            $item->setImg($itemImg);
            $item->setSize($size1);
            $item->setSize2($size2);
            $item->setSize3($variation['templeLength']);
            $item->setIsValid(1);
            $item->setUpc($upc);

            $result[] = $item;
        }
        echo "============================================\n";

        foreach($result as $res) {
           $res->sync();
        }
    }
}
