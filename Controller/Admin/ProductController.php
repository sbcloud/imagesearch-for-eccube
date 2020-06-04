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
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\Product;
use Eccube\Entity\ProductCategory;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductImage;
use Eccube\Entity\ProductStock;
use Eccube\Entity\ProductTag;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\ProductType;
use Eccube\Form\Type\Admin\SearchProductType;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CategoryRepository;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Repository\Master\ProductStatusRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductImageRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TagRepository;
use Eccube\Repository\TaxRuleRepository;
use Eccube\Service\CsvExportService;
use Eccube\Util\CacheUtil;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Knp\Component\Pager\Paginator;
use Eccube\Util\FormUtil;
use AlibabaCloud\Client\AlibabaCloud;
use Plugin\ImageSearch\Repository\ConfigRepository;

class ProductController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var CsvExportService
     */
    protected $csvExportService;

    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    /**
     * @var ProductImageRepository
     */
    protected $productImageRepository;

    /**
     * @var TaxRuleRepository
     */
    protected $taxRuleRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var ProductStatusRepository
     */
    protected $productStatusRepository;

    /**
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @var ConfigRepository
     */
    protected $Config;

    /**
     * ProductController constructor.
     *
     * @param CsvExportService $csvExportService
     * @param ProductClassRepository $productClassRepository
     * @param ProductImageRepository $productImageRepository
     * @param TaxRuleRepository $taxRuleRepository
     * @param CategoryRepository $categoryRepository
     * @param ProductRepository $productRepository
     * @param BaseInfoRepository $baseInfoRepository
     * @param PageMaxRepository $pageMaxRepository
     * @param ProductStatusRepository $productStatusRepository
     * @param TagRepository $tagRepository
     */
    public function __construct(
        CsvExportService $csvExportService,
        ProductClassRepository $productClassRepository,
        ProductImageRepository $productImageRepository,
        TaxRuleRepository $taxRuleRepository,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository,
        BaseInfoRepository $baseInfoRepository,
        PageMaxRepository $pageMaxRepository,
        ProductStatusRepository $productStatusRepository,
        TagRepository $tagRepository,
        ConfigRepository $configRepository
    ) {
        $this->csvExportService = $csvExportService;
        $this->productClassRepository = $productClassRepository;
        $this->productImageRepository = $productImageRepository;
        $this->taxRuleRepository = $taxRuleRepository;
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->pageMaxRepository = $pageMaxRepository;
        $this->productStatusRepository = $productStatusRepository;
        $this->tagRepository = $tagRepository;
        $this->configRepository = $configRepository;

        $this->Config = $this->configRepository->get();

        if ($this->Config) {
            AlibabaCloud::accessKeyClient($this->Config->getAccessKey(), $this->Config->getAccessSecret())->regionId($this->Config->getRegionId())->asDefaultClient();
        }
    }

    /**
     * @Route("/%eccube_admin_route%/product", name="admin_product")
     * @Route("/%eccube_admin_route%/product/page/{page_no}", requirements={"page_no" = "\d+"}, name="admin_product_page")
     * @Template("@ImageSearch/admin/Product/index.twig")
     */
    public function index(Request $request, $page_no = null, Paginator $paginator)
    {
        $builder = $this->formFactory
            ->createBuilder(SearchProductType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_INDEX_INITIALIZE, $event);

        $searchForm = $builder->getForm();

        /**
         * ページの表示件数は, 以下の順に優先される.
         * - リクエストパラメータ
         * - セッション
         * - デフォルト値
         * また, セッションに保存する際は mtb_page_maxと照合し, 一致した場合のみ保存する.
         **/
        $page_count = $this->session->get('eccube.admin.order.search.page_count',
            $this->eccubeConfig->get('eccube_default_page_count'));

        $page_count_param = (int) $request->get('page_count');
        $pageMaxis = $this->pageMaxRepository->findAll();

        if ($page_count_param) {
            foreach ($pageMaxis as $pageMax) {
                if ($page_count_param == $pageMax->getName()) {
                    $page_count = $pageMax->getName();
                    $this->session->set('eccube.admin.order.search.page_count', $page_count);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                /**
                 * 検索が実行された場合は, セッションに検索条件を保存する.
                 * ページ番号は最初のページ番号に初期化する.
                 */
                $page_no = 1;
                $searchData = $searchForm->getData();

                // 検索条件, ページ番号をセッションに保持.
                $this->session->set('eccube.admin.product.search', FormUtil::getViewData($searchForm));
                $this->session->set('eccube.admin.product.search.page_no', $page_no);
            } else {
                // 検索エラーの際は, 詳細検索枠を開いてエラー表示する.
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $page_count,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                /*
                 * ページ送りの場合または、他画面から戻ってきた場合は, セッションから検索条件を復旧する.
                 */
                if ($page_no) {
                    // ページ送りで遷移した場合.
                    $this->session->set('eccube.admin.product.search.page_no', (int) $page_no);
                } else {
                    // 他画面から遷移した場合.
                    $page_no = $this->session->get('eccube.admin.product.search.page_no', 1);
                }
                $viewData = $this->session->get('eccube.admin.product.search', []);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
            } else {
                /**
                 * 初期表示の場合.
                 */
                $page_no = 1;
                // submit default value
                $viewData = FormUtil::getViewData($searchForm);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

                // セッション中の検索条件, ページ番号を初期化.
                $this->session->set('eccube.admin.product.search', $viewData);
                $this->session->set('eccube.admin.product.search.page_no', $page_no);
            }
        }

        $qb = $this->productRepository->getQueryBuilderBySearchDataForAdmin($searchData);

        $event = new EventArgs(
            [
                'qb' => $qb,
                'searchData' => $searchData,
            ],
            $request
        );

        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_INDEX_SEARCH, $event);

        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $page_count,
            'has_errors' => false,
        ];
    }


    /**
     * @Route("/%eccube_admin_route%/product/product/new", name="admin_product_product_new")
     * @Route("/%eccube_admin_route%/product/product/{id}/edit", requirements={"id" = "\d+"}, name="admin_product_product_edit")
     * @Template("@admin/Product/product.twig")
     */
    public function edit(Request $request, $id = null, RouterInterface $router, CacheUtil $cacheUtil)
    {
        $has_class = false;
        if (is_null($id)) {
            $Product = new Product();
            $ProductClass = new ProductClass();
            $ProductStatus = $this->productStatusRepository->find(ProductStatus::DISPLAY_HIDE);
            $Product
                ->addProductClass($ProductClass)
                ->setStatus($ProductStatus);
            $ProductClass
                ->setVisible(true)
                ->setStockUnlimited(true)
                ->setProduct($Product);
            $ProductStock = new ProductStock();
            $ProductClass->setProductStock($ProductStock);
            $ProductStock->setProductClass($ProductClass);
        } else {
            $Product = $this->productRepository->find($id);
            if (!$Product) {
                throw new NotFoundHttpException();
            }
            // 規格無しの商品の場合は、デフォルト規格を表示用に取得する
            $has_class = $Product->hasProductClass();
            if (!$has_class) {
                $ProductClasses = $Product->getProductClasses();
                foreach ($ProductClasses as $pc) {
                    if (!is_null($pc->getClassCategory1())) {
                        continue;
                    }
                    if ($pc->isVisible()) {
                        $ProductClass = $pc;
                        break;
                    }
                }
                if ($this->BaseInfo->isOptionProductTaxRule() && $ProductClass->getTaxRule()) {
                    $ProductClass->setTaxRate($ProductClass->getTaxRule()->getTaxRate());
                }
                $ProductStock = $ProductClass->getProductStock();
            }
        }

        $builder = $this->formFactory
            ->createBuilder(ProductType::class, $Product);

        // 規格あり商品の場合、規格関連情報をFormから除外
        if ($has_class) {
            $builder->remove('class');
        }

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Product' => $Product,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE, $event);

        $form = $builder->getForm();

        if (!$has_class) {
            $ProductClass->setStockUnlimited($ProductClass->isStockUnlimited());
            $form['class']->setData($ProductClass);
        }

        // ファイルの登録
        $images = [];
        $ProductImages = $Product->getProductImage();
        foreach ($ProductImages as $ProductImage) {
            $images[] = $ProductImage->getFileName();
        }
        $form['images']->setData($images);

        $categories = [];
        $ProductCategories = $Product->getProductCategories();
        foreach ($ProductCategories as $ProductCategory) {
            /* @var $ProductCategory \Eccube\Entity\ProductCategory */
            $categories[] = $ProductCategory->getCategory();
        }
        $form['Category']->setData($categories);

        $Tags = $Product->getTags();
        $form['Tag']->setData($Tags);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                log_info('商品登録開始', [$id]);
                $Product = $form->getData();

                if (!$has_class) {
                    $ProductClass = $form['class']->getData();

                    // 個別消費税
                    if ($this->BaseInfo->isOptionProductTaxRule()) {
                        if ($ProductClass->getTaxRate() !== null) {
                            if ($ProductClass->getTaxRule()) {
                                $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                            } else {
                                $taxrule = $this->taxRuleRepository->newTaxRule();
                                $taxrule->setTaxRate($ProductClass->getTaxRate());
                                $taxrule->setApplyDate(new \DateTime());
                                $taxrule->setProduct($Product);
                                $taxrule->setProductClass($ProductClass);
                                $ProductClass->setTaxRule($taxrule);
                            }

                            $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                        } else {
                            if ($ProductClass->getTaxRule()) {
                                $this->taxRuleRepository->delete($ProductClass->getTaxRule());
                                $ProductClass->setTaxRule(null);
                            }
                        }
                    }
                    $this->entityManager->persist($ProductClass);

                    // 在庫情報を作成
                    if (!$ProductClass->isStockUnlimited()) {
                        $ProductStock->setStock($ProductClass->getStock());
                    } else {
                        // 在庫無制限時はnullを設定
                        $ProductStock->setStock(null);
                    }
                    $this->entityManager->persist($ProductStock);
                }

                // カテゴリの登録
                // 一度クリア
                /* @var $Product \Eccube\Entity\Product */
                foreach ($Product->getProductCategories() as $ProductCategory) {
                    $Product->removeProductCategory($ProductCategory);
                    $this->entityManager->remove($ProductCategory);
                }
                $this->entityManager->persist($Product);
                $this->entityManager->flush();

                $count = 1;
                $Categories = $form->get('Category')->getData();
                $categoriesIdList = [];
                foreach ($Categories as $Category) {
                    foreach ($Category->getPath() as $ParentCategory) {
                        if (!isset($categoriesIdList[$ParentCategory->getId()])) {
                            $ProductCategory = $this->createProductCategory($Product, $ParentCategory, $count);
                            $this->entityManager->persist($ProductCategory);
                            $count++;
                            /* @var $Product \Eccube\Entity\Product */
                            $Product->addProductCategory($ProductCategory);
                            $categoriesIdList[$ParentCategory->getId()] = true;
                        }
                    }
                    if (!isset($categoriesIdList[$Category->getId()])) {
                        $ProductCategory = $this->createProductCategory($Product, $Category, $count);
                        $this->entityManager->persist($ProductCategory);
                        $count++;
                        /* @var $Product \Eccube\Entity\Product */
                        $Product->addProductCategory($ProductCategory);
                        $categoriesIdList[$ParentCategory->getId()] = true;
                    }
                }

                // 画像の登録
                $add_images = $form->get('add_images')->getData();
                foreach ($add_images as $add_image) {
                    $ProductImage = new \Eccube\Entity\ProductImage();
                    $ProductImage
                        ->setFileName($add_image)
                        ->setProduct($Product)
                        ->setSortNo(1);
                    $Product->addProductImage($ProductImage);
                    $this->entityManager->persist($ProductImage);

                    // 移動
                    $file = new File($this->eccubeConfig['eccube_temp_image_dir'].'/'.$add_image);
                    $file->move($this->eccubeConfig['eccube_save_image_dir']);

                    if ($this->Config) {
                        // add image search
                        $filePath = $this->eccubeConfig['eccube_save_image_dir'] . '/' . $add_image;

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

                        AlibabaCloud::ImageSearch()
                            ->V20190325()
                            ->AddImage()
                            ->contentType('application/x-www-form-urlencoded; charset=UTF-8')
                            ->withInstanceName($this->Config->getInstanceName())
                            ->withProductId($Product->getId())
                            ->withPicName($add_image)
                            ->withPicContent(base64_encode(file_get_contents($filePath)))
                            ->debug(false)
                            ->request();
                        sleep(1);
                    }
                }

                // 画像の削除
                $delete_images = $form->get('delete_images')->getData();
                foreach ($delete_images as $delete_image) {
                    $ProductImage = $this->productImageRepository
                        ->findOneBy(['file_name' => $delete_image]);

                    // 追加してすぐに削除した画像は、Entityに追加されない
                    if ($ProductImage instanceof ProductImage) {
                        $Product->removeProductImage($ProductImage);
                        $this->entityManager->remove($ProductImage);
                    }
                    $this->entityManager->persist($Product);

                    if ($this->Config) {
                        // delete image search
                        AlibabaCloud::ImageSearch()
                            ->V20190325()
                            ->DeleteImage()
                            ->contentType('application/x-www-form-urlencoded; charset=UTF-8')
                            ->withInstanceName($this->Config->getInstanceName())
                            ->withProductId($Product->getId())
                            ->withPicName($delete_image)
                            ->debug(false)
                            ->request();
                        sleep(1);
                    }

                    // 削除
                    $fs = new Filesystem();
                    $fs->remove($this->eccubeConfig['eccube_save_image_dir'].'/'.$delete_image);
                }
                $this->entityManager->persist($Product);
                $this->entityManager->flush();

                $sortNos = $request->get('sort_no_images');
                if ($sortNos) {
                    foreach ($sortNos as $sortNo) {
                        list($filename, $sortNo_val) = explode('//', $sortNo);
                        $ProductImage = $this->productImageRepository
                            ->findOneBy([
                                'file_name' => $filename,
                                'Product' => $Product,
                            ]);
                        $ProductImage->setSortNo($sortNo_val);
                        $this->entityManager->persist($ProductImage);
                    }
                }
                $this->entityManager->flush();

                // 商品タグの登録
                // 商品タグを一度クリア
                $ProductTags = $Product->getProductTag();
                foreach ($ProductTags as $ProductTag) {
                    $Product->removeProductTag($ProductTag);
                    $this->entityManager->remove($ProductTag);
                }

                // 商品タグの登録
                $Tags = $form->get('Tag')->getData();
                foreach ($Tags as $Tag) {
                    $ProductTag = new ProductTag();
                    $ProductTag
                        ->setProduct($Product)
                        ->setTag($Tag);
                    $Product->addProductTag($ProductTag);
                    $this->entityManager->persist($ProductTag);
                }

                $Product->setUpdateDate(new \DateTime());
                $this->entityManager->flush();

                log_info('商品登録完了', [$id]);

                $event = new EventArgs(
                    [
                        'form' => $form,
                        'Product' => $Product,
                    ],
                    $request
                );
                $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_COMPLETE, $event);

                $this->addSuccess('admin.common.save_complete', 'admin');

                if ($returnLink = $form->get('return_link')->getData()) {
                    try {
                        // $returnLinkはpathの形式で渡される. pathが存在するかをルータでチェックする.
                        $pattern = '/^'.preg_quote($request->getBasePath(), '/').'/';
                        $returnLink = preg_replace($pattern, '', $returnLink);
                        $result = $router->match($returnLink);
                        // パラメータのみ抽出
                        $params = array_filter($result, function ($key) {
                            return 0 !== \strpos($key, '_');
                        }, ARRAY_FILTER_USE_KEY);

                        // pathからurlを再構築してリダイレクト.
                        return $this->redirectToRoute($result['_route'], $params);
                    } catch (\Exception $e) {
                        // マッチしない場合はログ出力してスキップ.
                        log_warning('URLの形式が不正です。');
                    }
                }

                $cacheUtil->clearDoctrineCache();

                return $this->redirectToRoute('admin_product_product_edit', ['id' => $Product->getId()]);
            }
        }

        // 検索結果の保持
        $builder = $this->formFactory
            ->createBuilder(SearchProductType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Product' => $Product,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_EDIT_SEARCH, $event);

        $searchForm = $builder->getForm();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
        }

        // Get Tags
        $TagsList = $this->tagRepository->getList();

        // ツリー表示のため、ルートからのカテゴリを取得
        $TopCategories = $this->categoryRepository->getList(null);
        $ChoicedCategoryIds = array_map(function ($Category) {
            return $Category->getId();
        }, $form->get('Category')->getData());

        return [
            'Product' => $Product,
            'Tags' => $Tags,
            'TagsList' => $TagsList,
            'form' => $form->createView(),
            'searchForm' => $searchForm->createView(),
            'has_class' => $has_class,
            'id' => $id,
            'TopCategories' => $TopCategories,
            'ChoicedCategoryIds' => $ChoicedCategoryIds,
        ];
    }

    /**
     * ProductCategory作成
     *
     * @param \Eccube\Entity\Product $Product
     * @param \Eccube\Entity\Category $Category
     * @param integer $count
     *
     * @return \Eccube\Entity\ProductCategory
     */
    private function createProductCategory($Product, $Category, $count)
    {
        $ProductCategory = new ProductCategory();
        $ProductCategory->setProduct($Product);
        $ProductCategory->setProductId($Product->getId());
        $ProductCategory->setCategory($Category);
        $ProductCategory->setCategoryId($Category->getId());

        return $ProductCategory;
    }

    /**
     * @Route("/%eccube_admin_route%/product/product/{id}/delete", requirements={"id" = "\d+"}, name="admin_product_product_delete", methods={"DELETE"})
     */
    public function delete(Request $request, $id = null, CacheUtil $cacheUtil)
    {
        $this->isTokenValid();
        $session = $request->getSession();
        $page_no = intval($session->get('eccube.admin.product.search.page_no'));
        $page_no = $page_no ? $page_no : Constant::ENABLED;
        $message = null;
        $success = false;

        if (!is_null($id)) {
            /* @var $Product \Eccube\Entity\Product */
            $Product = $this->productRepository->find($id);
            if (!$Product) {
                if ($request->isXmlHttpRequest()) {
                    $message = trans('admin.common.delete_error_already_deleted');

                    return $this->json(['success' => $success, 'message' => $message]);
                } else {
                    $this->deleteMessage();
                    $rUrl = $this->generateUrl('admin_product_page', ['page_no' => $page_no]).'?resume='.Constant::ENABLED;

                    return $this->redirect($rUrl);
                }
            }

            if ($Product instanceof Product) {
                log_info('商品削除開始', [$id]);

                $deleteImages = $Product->getProductImage();
                $ProductClasses = $Product->getProductClasses();

                try {
                    $this->productRepository->delete($Product);
                    $this->entityManager->flush();

                    $event = new EventArgs(
                        [
                            'Product' => $Product,
                            'ProductClass' => $ProductClasses,
                            'deleteImages' => $deleteImages,
                        ],
                        $request
                    );
                    $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_DELETE_COMPLETE, $event);
                    $deleteImages = $event->getArgument('deleteImages');

                    // 画像ファイルの削除(commit後に削除させる)
                    foreach ($deleteImages as $deleteImage) {
                        try {
                            $fs = new Filesystem();
                            $fs->remove($this->eccubeConfig['eccube_save_image_dir'].'/'.$deleteImage);
                        } catch (\Exception $e) {
                            // エラーが発生しても無視する
                        }
                    }

                    log_info('商品削除完了', [$id]);

                    if ($this->Config) {
                        // delete image search
                        AlibabaCloud::ImageSearch()
                            ->V20190325()
                            ->DeleteImage()
                            ->contentType('application/x-www-form-urlencoded; charset=UTF-8')
                            ->withInstanceName($this->Config->getInstanceName())
                            ->withProductId($id)
                            ->debug(false)
                            ->request();
                        sleep(1);
                    }

                    $success = true;
                    $message = trans('admin.common.delete_complete');

                    $cacheUtil->clearDoctrineCache();
                } catch (ForeignKeyConstraintViolationException $e) {
                    log_info('商品削除エラー', [$id]);
                    $message = trans('admin.common.delete_error_foreign_key', ['%name%' => $Product->getName()]);
                }
            } else {
                log_info('商品削除エラー', [$id]);
                $message = trans('admin.common.delete_error');
            }
        } else {
            log_info('商品削除エラー', [$id]);
            $message = trans('admin.common.delete_error');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => $success, 'message' => $message]);
        } else {
            if ($success) {
                $this->addSuccess($message, 'admin');
            } else {
                $this->addError($message, 'admin');
            }

            $rUrl = $this->generateUrl('admin_product_page', ['page_no' => $page_no]).'?resume='.Constant::ENABLED;

            return $this->redirect($rUrl);
        }
    }

    /**
     * @Route("/%eccube_admin_route%/product/product/{id}/copy", requirements={"id" = "\d+"}, name="admin_product_product_copy", methods={"POST"})
     */
    public function copy(Request $request, $id = null)
    {
        $this->isTokenValid();

        if (!is_null($id)) {
            $Product = $this->productRepository->find($id);
            if ($Product instanceof Product) {
                $CopyProduct = clone $Product;
                $CopyProduct->copy();
                $ProductStatus = $this->productStatusRepository->find(ProductStatus::DISPLAY_HIDE);
                $CopyProduct->setStatus($ProductStatus);

                $CopyProductCategories = $CopyProduct->getProductCategories();
                foreach ($CopyProductCategories as $Category) {
                    $this->entityManager->persist($Category);
                }

                // 規格あり商品の場合は, デフォルトの商品規格を取得し登録する.
                if ($CopyProduct->hasProductClass()) {
                    $dummyClass = $this->productClassRepository->findOneBy([
                        'visible' => false,
                        'ClassCategory1' => null,
                        'ClassCategory2' => null,
                        'Product' => $Product,
                    ]);
                    $dummyClass = clone $dummyClass;
                    $dummyClass->setProduct($CopyProduct);
                    $CopyProduct->addProductClass($dummyClass);
                }

                $CopyProductClasses = $CopyProduct->getProductClasses();
                foreach ($CopyProductClasses as $Class) {
                    $Stock = $Class->getProductStock();
                    $CopyStock = clone $Stock;
                    $CopyStock->setProductClass($Class);
                    $this->entityManager->persist($CopyStock);

                    $TaxRule = $Class->getTaxRule();
                    if ($TaxRule) {
                        $CopyTaxRule = clone $TaxRule;
                        $CopyTaxRule->setProductClass($Class);
                        $CopyTaxRule->setProduct($CopyProduct);
                        $this->entityManager->persist($CopyTaxRule);
                    }
                    $this->entityManager->persist($Class);
                }
                $Images = $CopyProduct->getProductImage();
                $copyFilenameList = [];
                foreach ($Images as $Image) {
                    // 画像ファイルを新規作成
                    $extension = pathinfo($Image->getFileName(), PATHINFO_EXTENSION);
                    $filename = date('mdHis').uniqid('_').'.'.$extension;
                    try {
                        $fs = new Filesystem();
                        $fs->copy($this->eccubeConfig['eccube_save_image_dir'].'/'.$Image->getFileName(), $this->eccubeConfig['eccube_save_image_dir'].'/'.$filename);
                    } catch (\Exception $e) {
                        // エラーが発生しても無視する
                    }
                    $Image->setFileName($filename);
                    $copyFilenameList[] = $filename;
                    $this->entityManager->persist($Image);
                }
                $Tags = $CopyProduct->getProductTag();
                foreach ($Tags as $Tag) {
                    $this->entityManager->persist($Tag);
                }

                $this->entityManager->persist($CopyProduct);

                $this->entityManager->flush();

                $event = new EventArgs(
                    [
                        'Product' => $Product,
                        'CopyProduct' => $CopyProduct,
                        'CopyProductCategories' => $CopyProductCategories,
                        'CopyProductClasses' => $CopyProductClasses,
                        'images' => $Images,
                        'Tags' => $Tags,
                    ],
                    $request
                );

                if ($this->Config && $copyFilenameList) {
                    foreach ($copyFilenameList as $copyFilenameItem) {
                        $filePath = $this->eccubeConfig['eccube_save_image_dir'] . '/' . $copyFilenameItem;
                        //登録済みの商品をコピする場合、画像圧縮不要
                        AlibabaCloud::ImageSearch()
                            ->V20190325()
                            ->AddImage()
                            ->contentType('application/x-www-form-urlencoded; charset=UTF-8')
                            ->withInstanceName($this->Config->getInstanceName())
                            ->withProductId($CopyProduct->getId())
                            ->withPicName($copyFilenameItem)
                            ->withPicContent(base64_encode(file_get_contents($filePath)))
                            ->debug(false)
                            ->request();
                        sleep(1);
                    }
                }
                $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_COPY_COMPLETE, $event);

                $this->addSuccess('admin.product.copy_complete', 'admin');

                return $this->redirectToRoute('admin_product_product_edit', ['id' => $CopyProduct->getId()]);
            } else {
                $this->addError('admin.product.copy_error', 'admin');
            }
        } else {
            $msg = trans('admin.product.copy_error');
            $this->addError($msg, 'admin');
        }

        return $this->redirectToRoute('admin_product');
    }

}
