<?php

/*
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Plugin\ImageSearch\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\ImageSearch\Repository\ConfigRepository;
//use Eccube\Entity\Product;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Eccube\Form\Type\AddCartType;
use Eccube\Form\Type\Master\ProductListMaxType;
use Eccube\Form\Type\Master\ProductListOrderByType;
use Eccube\Form\Type\SearchProductType;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\ProductImageRepository;
use Symfony\Component\HttpFoundation\Response;
use AlibabaCloud\Client\AlibabaCloud;

use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Knp\Component\Pager\Paginator;

class ProductAjaxController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var productImageRepository
     */
    protected $productImageRepository;

    public function __construct(ConfigRepository $configRepository, ProductImageRepository $productImageRepository)
    {
        $this->productImageRepository = $productImageRepository;
        $this->configRepository  = $configRepository;
    }

    /**
     * @Method("GET")
     * @Route("/%eccube_admin_route%/imageSearch")
     */
    public function index()
    {
        $Config = $this->configRepository->get();

        if (!$Config) {
            return $this->json([
                'status' => false,
                'message' => 'Please to config aliyun image search',
            ]);
        }

        $entityManager = $this->getDoctrine()->getEntityManager();

        $query = $entityManager->createQuery(
            'SELECT pi
            FROM Eccube\Entity\ProductImage pi'
        );

        $list = $query->getResult();

        AlibabaCloud::accessKeyClient($Config->getAccessKey(), $Config->getAccessSecret())->regionId($Config->getRegionId())->asDefaultClient();

        foreach ($list as $item) {
            $filePath = $this->eccubeConfig['eccube_save_image_dir'] . '/' . $item->getFileName();
            $id = $item->getProduct()->getId();

            $maxWidth = 1024;
            $maxHeight = 1024;
            $imagick = new \Imagick();
            $imagick->readImage($filePath);
            $orgWidth = $imagick->getImageWidth();
            $orgHeight = $imagick->getImageHeight();
            if ($orgWidth > $maxWidth || $orgHeight > $maxHeight) {
                $ratio = $orgWidth / $orgHeight;
                if ($maxWidth / $maxHeight > $ratio) {
                    $maxWidth = $maxHeight * $ratio;
                } else {
                    $maxHeight = $maxWidth / $ratio;
                }
                $imagick->scaleImage($maxWidth, $maxHeight);
                $imagick->setCompressionQuality(80);
                $imagick->writeImage($filePath);
            }
            $imagick->clear();
            $imagick->destroy();

            $result = AlibabaCloud::ImageSearch()
                ->V20190325()
                ->AddImage()
                ->contentType('application/x-www-form-urlencoded; charset=UTF-8')
                ->withInstanceName($Config->getInstanceName())
                ->withProductId($id)
                ->withPicName($item->getFileName())
                ->withPicContent(base64_encode(file_get_contents($filePath)))
                ->debug(false)
                ->request();
            // file_put_contents($id . $item->getFileName() . '.txt', var_export($result->toArray(), true));
            sleep(1);
        }

        return $this->json(['status' => true, 'message' => 'Success', 'data' => count($list)]);
    }
}
