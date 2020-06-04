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

namespace Plugin\ImageSearch\Controller;

use Eccube\Controller\AbstractController;
use Plugin\ImageSearch\Repository\ConfigRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Knp\Component\Pager\Paginator;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Response;
use AlibabaCloud\Client\AlibabaCloud;
use Eccube\Repository\ProductRepository;
use Eccube\Form\Type\AddCartType;
use Eccube\Form\Type\Master\ProductListMaxType;
use Eccube\Form\Type\Master\ProductListOrderByType;
use Eccube\Form\Type\SearchProductType;

class ImageSearchController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * ConfigController constructor.
     *
     * @param ConfigRepository $configRepository
     */
    public function __construct(ConfigRepository $configRepository, ProductRepository $productRepository)
    {
        $this->configRepository  = $configRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @Method("GET")
     * @Route("/imageSearch")
     */
    public function index()
    {
        $message = 'Image Search page';

        return new Response($message);
    }

    /**
     * @Method("POST")
     * @Route("/imageSearch/upload")
     */
    public function upload()
    {
        $Config = $this->configRepository->get();

        if (!$Config) {
            return $this->json([
                'status' => false,
                'message' => 'Please to config aliyun image search',
            ]);
        }

        AlibabaCloud::accessKeyClient($Config->getAccessKey(), $Config->getAccessSecret())->regionId($Config->getRegionId())->asDefaultClient();

        if (!isset($_FILES['upload_image'])) {
            return $this->json([
                'status' => false,
                'message' => 'Please upload image',
            ]);
        }
        if (!in_array($_FILES['upload_image']['type'], ['image/jpeg', 'image/png'])) {
            return $this->json([
                'status' => false,
                'message' => 'Please upload pictures in jpg or png format',
            ]);
        }

        $maxWidth = 1024;
        $maxHeight = 1024;
        $imagick = new \Imagick();
        $imagick->readImage($_FILES['upload_image']['tmp_name']);
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
            $imagick->writeImage($_FILES['upload_image']['tmp_name']);
        }
        $imagick->clear();
        $imagick->destroy();

        if ($_FILES['upload_image']['size'] > 1048576) {
            return $this->json(['status' => false, 'message' => 'Please upload an image smaller than 1MB']);
        }
        if ($_FILES['upload_image']['error'] > 0) {
            return $this->json([
                'status' => false,
                'message' => $_FILES['upload_image']['error'],
            ]);
        }

        $img_info = getimagesize($_FILES['upload_image']['tmp_name']);
        if (!$img_info) {
            return $this->json([
                'status' => false,
                'message' => 'Upload Fail',
            ]);
        }
        if ($img_info[0] < 200 || $img_info[0] > 1024 || $img_info[1] < 200 || $img_info[1] > 1024) {
            return $this->json([
                'status' => false,
                'message' => 'The pixel of the picture length and width must be greater than 200, and less than 1024',
            ]);
        }

        $result = AlibabaCloud::ImageSearch()->V20190325()->SearchImage()->contentType('application/x-www-form-urlencoded; charset=UTF-8')->withInstanceName($Config->getInstanceName())->withPicContent(base64_encode(file_get_contents($_FILES['upload_image']['tmp_name'])))->debug(false)->request();

        $imageSearch = $result->toArray();
        if (!isset($imageSearch['Code'], $imageSearch['Success']) || $imageSearch['Code'] != 0 || $imageSearch['Success'] != true) {
            return $this->json(['status' => false, 'message' => $imageSearch['Msg']]);
        }

        // search product list by product id
        $product_ids = array_map(function ($v){
            return $v['ProductId'];
        }, $imageSearch['Auctions']);

        $product_ids = array_unique($product_ids);
        if (!$product_ids) {
            return $this->json(['status' => false, 'message' => 'No result']);
        }

        return $this->json(['status' => true, 'message' => 'Success', 'data' => $product_ids]);
    }

    /**
     * @Method("GET")
     * @Route("/imageSearch/list/{ids}")
     * @Template("@ImageSearch/default/list.twig")
     */
    public function list($ids = null, Paginator $paginator)
    {
        if (!$ids) {
            return new Response('No Results');
        }
        $idArrays = explode(',', $ids);
        $idArrays = array_unique($idArrays);
        $idArrays = array_filter($idArrays, function ($v) {
            return is_numeric($v);
        });

        if (!$idArrays) {
            return new Response('No Results');
        }

        $entityManager = $this->getDoctrine()->getEntityManager();

        $q = implode(',', $idArrays);

        $query = $entityManager->createQuery("SELECT p
            FROM Eccube\Entity\Product p
            WHERE p.id IN ({$q}) AND p.Status = 1
            ")->useResultCache(true, $this->eccubeConfig['eccube_result_cache_lifetime_short']);

        /** @var SlidingPagination $pagination */
        $pagination = $paginator->paginate($query);

        $ProductsAndClassCategories = $this->productRepository->findProductsWithSortedClassCategories($idArrays, 'p.id');

        // addCart form
        $forms = [];
        $list  = [];
        foreach ($pagination as $key => $Product) {
            /* @var $builder \Symfony\Component\Form\FormBuilderInterface */
            $builder     = $this->formFactory->createNamedBuilder('', AddCartType::class, null, [
                'product' => $ProductsAndClassCategories[$Product->getId()],
                'allow_extra_fields' => true,
            ]);
            $addCartForm = $builder->getForm();

            $forms[$Product->getId()] = $addCartForm->createView();

            $list[] = [
                'id' => $Product->getId(),
                'main_list_image' => $Product->getMainListImage(),
                'name' => $Product->getName(),
                'description_list' => $Product->getDescriptionList(),
                'hasProductClass' => $Product->hasProductClass(),
                'getPrice02Min' => $Product->getPrice02Min(),
                'getPrice02Max' => $Product->getPrice02Max(),
                'getPrice02IncTaxMin' => $Product->getPrice02IncTaxMin(),
                'getPrice02IncTaxMax' => $Product->getPrice02IncTaxMax(),
                'sortIndex' => array_search($Product->getId(), $idArrays)
            ];
        }

        usort($list, function ($a, $b) {
            return $a['sortIndex'] - $b['sortIndex'];
        });

        return [
            'pagination' => $list,
            'forms' => $forms,
        ];
    }
}
