<?php
/*
 * @author : Sabeel Nasir <sabeel.chishti2@gmail.com>
 */

class NotexOrdersController extends Controller
{
	/**
	 * @var string the default layout for the views. Defaults to '//layouts/column2', meaning
	 * using two-column layout. See 'protected/views/layouts/column2.php'.
	 */
	public $layout='//layouts/main';

	/**
	 * @return array action filters
	 */
	public function filters()
	{
		return array(
			'accessControl', // perform access control for CRUD operations
			'postOnly + delete', // we only allow deletion via POST request
		);
	}

	/**
	 * Specifies the access control rules.
	 * This method is used by the 'accessControl' filter.
	 * @return array access control rules
	 */
	public function accessRules()
	{
		return array(
			array('allow', // allow authenticated user to perform 'create' and 'update' actions
				'users'=>array('@'),
			),
			array('deny',  // deny all users
				'users'=>array('*'),
			),
		);
	}

	//===========================LAB BOOKING FORM FUNCTION HERE==========================//
	public function actionLab_booking()
	{
		$model = new NotexOrders();

		if(isset($_POST['NotexOrders']))
		{


			$transaction = Yii::app()->db->beginTransaction();
			$model->attributes=$_POST['NotexOrders'];
//            echo '<pre>';print_r($model);echo '<pre>';exit();

			$model->entered_on=date('Y-m-d H:i:s');
			$model->entered_by=Yii::app()->user->id;
			if(!empty($model->comment)){ $model->note.="\n Eligibility Note: ".$model->comment; }
			//----Search Patient by 'mr_no' and get 'patient_id'----//
			//$patient_id = Yii::app()->db->createCommand("SELECT id FROM rm_pr_patients WHERE MR_NO='{$model->reference_no}'")->queryScalar();
			$registered_patient_id = $_POST['registered_patient_id'];
			if($registered_patient_id > 0)
			{
				$model->patient_id = $registered_patient_id;
			}
			// echo '<pre>'; print_r($model); exit();
			if($model->save())
			{

				if(isset($_POST['booked_test_ids']))		//---If any test booked---//
				{
					foreach($_POST['booked_test_ids'] as $index=>$test_id)
					{
						$model_detail=new NotexOrdersDetail();
						$model_detail->order_id=$model->id;
						$model_detail->subservice_id=$test_id;
						$model_detail->service_name=$_POST['booked_test_names'][$index];
						$model_detail->entered_on=date('Y-m-d H:i:s');
						$model_detail->entered_by=Yii::app()->user->id;
						if($model_detail->save())
						{
							Yii::app()->user->setFlash('test_booked','Test Booked');
						}
						else
						{

							Yii::app()->user->setFlash('failed','Failed');
						}

					}
				}
				if($model->home_request==1)
				{
					$row_id=$model->id;
					//==============SEND SMS & EMAIL TO HOME COLLECTION CENTER ALERT========//
					$client_app_events=new ClientAppEvents();
					$client_app_events->send_home_services_alert($row_id,$model->patient_name,$model->mobile_no,$model->for_branch_id);

					//=====================================================================//
				}
				$transaction->commit();
				$model=new NotexOrders();
				Yii::app()->user->setFlash('req_sent','Request Sent');
			}
			else
			{
				$transaction->rollback();
				Yii::app()->user->setFlash('failed','Failed');
			}
		}

		//echo '<pre>'; print_r($model); exit();
		$this->render('lab-booking',array(
			'model' => $model
		));
	}

	/**
	* @author: Usama
	* @description: GETTING PANEL EMPLOYEE INFO via emp_card_no
	*/
	public function actiongetPanelEmployee()
	{
        ini_set('display_errors',1);
        ini_set('display_startup_errors',1);
        error_reporting(E_ALL);

        if(Yii::app()->getRequest()->getPost('emp_card_no') && Yii::app()->request->getPost('for_branch_id')){
			$employee_info = array();
			$emp_card_no = Yii::app()->getRequest()->getPost('emp_card_no');
			$for_branch_id = Yii::app()->request->getPost('for_branch_id');
			$getData = Yii::app()->db->createCommand("SELECT * FROM org_employees WHERE emp_card_no = :ecn")->bindParam(":ecn",$emp_card_no,PDO::PARAM_STR)->queryRow();
			if(!empty($getData)){
				$emp_id = $getData['id'];
				$panel_id = $getData['org_branch_id'];
				/*
				 * Check if this panel exists in selected for_clinic_branch_id : By Sabeel
				 */
				$check_emp_panel_with_branch = Yii::app()->db->createCommand("SELECT id FROM clinic_orgs WHERE clinic_branch_id = {$for_branch_id} AND org_branch_id = {$panel_id} AND active = 'Y'")->queryScalar();
				if(!empty($check_emp_panel_with_branch)) {

                    $dependents_list = Yii::app()->db->createCommand("SELECT * FROM entitle_patient WHERE employee_id = {$emp_id}")->queryAll();
                    if (!empty($dependents_list)) {
                        foreach ($dependents_list as $dl) {
                            $patient_id = $dl['patient_id'];
                            $branch_id = $dl['branch_id'];
                            $relation_id = $dl['relation_id'];
                            $patient_info = OliveCliqCache::patient_info($patient_id);
                            $patient_name = trim($patient_info['FirstName']) . ' ' . trim($patient_info['LastName']);
                            $patient_dob = $patient_info['DateofBirth'];
                            $patient_cell_no = $patient_info['CellNo'];
                            $patient_mr_no = $patient_info['MR_NO'];
                            $relation = OliveCliqCache::particular($relation_id)['Name'];
                            $employee_info[] = array('patient_id' => $patient_id, 'patient_name' => $patient_name, 'patient_dob' => $patient_dob, 'relation' => $relation, 'panel_id' => $panel_id, 'branch_id' => $branch_id, 'patient_cell_no' => $patient_cell_no, 'patient_mr_no' => $patient_mr_no);
                        }
                    }
                }else{
                    $employee_info[] = array('patient_id' => 0 ,'message' => 'Employee Panel registered for another Location !');
                }
			}
			echo CJSON::encode($employee_info);
		}
	}

	public function actionLoad_tests()
	{
		if (isset($_POST['branch_id'])) {
			$branch_id = $_POST['branch_id'];

			// load all test against this branch_id from clinic_lab_tests
			// $clinic_lab_tests = Yii::app()->db->createCommand("SELECT test_id,notes FROM clinic_lab_tests WHERE clinic_id = {$branch_id}")->queryAll();

				//====Getting Clinic lab Tests from Cache====//
			$clinic_lab_tests = LabTestsCache::get_clinic_lab_tests($branch_id,1);
			$lab_tests_array = array();
			if (!empty($clinic_lab_tests)) {
				foreach ($clinic_lab_tests as $lt) {
					$test_id = $lt['test_id'];
					$test_name = $lt['notes'];
					// $dept_name = OliveCliqCache::lab_department_info($lt['dept_id'])['name'];
					$dept_name = OliveCliqCache::lab_sub_department_info($lt['sub_dept_id'])['name'];
					$lab_tests_array[] = array('test_id'=>$test_id, 'dept_name'=>$dept_name , 'test_name'=>$test_name);
				}
			}else {
				$lab_tests_array[] = array('test_id' => 0, 'test_name' => 'No Test Found');
			}
			echo json_encode($lab_tests_array);
		}
	}

	//======================================================================//

	//==========================TODAY BOOKING GRID===========================//
	public function actionTodayBooking()
	{
		$this->render('today_booking');
	}
	//=======================================================================//

	//==========================ALL BOOKING GRID===========================//
	public function actionAllBookings()
	{
		$this->render('all_bookings');
	}
	public function actionViewOrdersDetail()
	{
		$this->layout=false;
		$this->render('view_orders_detail');
	}
	/*
	 * Cancel Booked Request before request is finalized from carepoint
	 */
	public function actionCancelBookedRequest()
    {
        $response_array = array('responseStatus' => '500', 'responseMessage'=>'Internal Server Error');
        if(Yii::app()->request->getPost('order_id')) {
            $transaction = Yii::app()->db->beginTransaction();
            try {
                $order_id = Yii::app()->request->getPost('order_id');
                /*
                 * Delete checkins if there any in notex_orders_detail
                 */
                $checkin_ids = Yii::app()->db->createCommand("Select checkin_id From notex_orders_detail Where order_id = {$order_id} AND checkin_id IS NOT NULL Group By checkin_id")->queryAll();
                $visit_count = 0;
                if(!empty($checkin_ids)) {
                    foreach ($checkin_ids as $checkin_row) {
                        $checkin_id = $checkin_row['checkin_id'];
                        if (!empty($checkin_id) && $checkin_id != null) {
                            $check_visit_services = Yii::app()->db->createCommand("Select id From visit_services Where checkin_id = {$checkin_id}")->queryScalar();
                            /*
                             * Delete notex-order-detail & checkins only if there are not visit-services against this checkin
                             */
                            if (empty($check_visit_services)) {
                                Checkins::model()->deleteByPk($checkin_id);
                                /*
                                 * Delete notex_orders_detail rows for this checkin & order-id
                                 */
                                NotexOrdersDetail::model()->deleteAll('order_id=:o_id AND checkin_id = :c_id', array(':o_id' => $order_id, ':c_id' => $checkin_id));
                            } else {
                                $visit_count++;
                            }
                        }
                    }
                }

                /*
                 * Delete notex-order only if there are not visit-services OR no checkin-id in 'notex-orders-detail'
                 */
                if($visit_count == 0) {
                    NotexOrdersDetail::model()->deleteAll('order_id=:o_id AND checkin_id IS NULL', array(':o_id' => $order_id));
                    NotexOrders::model()->deleteByPk($order_id);
                    $response_array = array('responseStatus'=>'200', 'responseMessage'=>'Lab Request Successfully Cancelled !');
                }else{
                    $deleteRows = NotexOrdersDetail::model()->deleteAll('order_id=:o_id AND checkin_id IS NULL', array(':o_id' => $order_id));
                    if($deleteRows > 0) {
                        $response_array = array('responseStatus' => '201', 'responseMessage' => 'Pending Tests of this Request Successfully Cancelled !');
                    }else{
                        $response_array = array('responseStatus' => '201', 'responseMessage' => 'Request cannot be cancelled on this stage !');
                    }
                }
                $transaction->commit();

            }catch (Exception $e){
                $transaction->rollback();
                $response_array = array('responseStatus'=>'400', 'responseMessage'=>'Something Wrong Happened !');
            }

        }
        echo CJSON::encode($response_array);
    }
	//=======================================================================//

	//===========================UPDATE LAB BOOKING===========================//
	public function actionUpdateLabBooking($id)
	{
		$transaction = Yii::app()->db->beginTransaction();
		$model=$this->loadModel($id);
		// echo '<pre>'; print_r($model); exit();
		if(isset($_POST['NotexOrders']))
		{
			//--Delete all order_details against 'model->id' on UPdating--//
			//Yii::app()->db->createCommand("Delete FROM notex_orders_detail WHERE order_id = {$model->id}")->execute();
			//NotexOrdersDetail::model()->deleteAll("order_id='".$model->id."'");
			$model->attributes=$_POST['NotexOrders'];
			$model->entered_on=date('Y-m-d H:i:s');
			$model->entered_by=Yii::app()->user->id;
			$for_branch_id=$model->for_branch_id;
			//----Search Patient by 'mr_no' and get 'patient_id'----//
			$patient_id = Yii::app()->db->createCommand("SELECT id FROM rm_pr_patients WHERE MR_NO='{$model->reference_no}'")->queryScalar();
			if(!empty($patient_id))
			{
				$model->patient_id = $patient_id;
			}
			if(!isset($_POST['NotexOrders']['abnormal_sms'])){ $model->abnormal_sms = "N"; }
			if(!isset($_POST['NotexOrders']['medicines_alert'])){ $model->medicines_alert = "N"; }
			if(!isset($_POST['NotexOrders']['home_request'])){ $model->home_request = 0;}

			//---Checking 'status' if all tests processed then update 'status'=1---//
			$order_count = Yii::app()->db->createCommand("SELECT COUNT(id) as total, SUM(case when checkin_id IS NOT NULL then 1 else 0 end) as completed from notex_orders_detail where order_id = {$model->id}")->queryRow();
			if($order_count['total']==$order_count['completed'])
			{
				$model->status=1;
			}
			//echo '<pre>'; print_r($model); exit();
			if($model->save())
			{
				if(isset($_POST['booked_test_ids']))		//---If any test booked---//
				{

					foreach($_POST['booked_test_ids'] as $index=>$test_id)
					{
						//echo '<pre>'; print_r($_POST['booked_test_ids']); exit();

						//==============IF ANY NEW TESTS BOOKED IN UPDATING==========//
						if(!isset($_POST['booked_orders'][$index]))
						{
							$checkInTable = Yii::app()->db->createCommand("SELECT id FROM notex_orders_detail WHERE subservice_id={$test_id} AND order_id={$model->id}")->queryScalar();
							if(empty($checkInTable))
							{
								$model_detail = new NotexOrdersDetail();
								$model_detail->order_id=$model->id;
								$model_detail->subservice_id=$test_id;
								$model_detail->service_name=$_POST['booked_test_names'][$index];
								$model_detail->entered_on=date('Y-m-d H:i:s');
								$model_detail->entered_by=Yii::app()->user->id;
								$model_detail->save();
							}
						}

					}
				}
				$transaction->commit();

				Yii::app()->user->setFlash('req_updated','Request Updated');
				$this->redirect(array('NotexOrders/AllBookings'));		//--Reload AllBookings screen---//
			}
			else
			{
				$transaction->rollback();
			}
		}
		$this->render('update_lab_booking',array('model'=>$model));
	}

	public function actionLoadTestsForUpdate()		//---Will get all tests & flag for booked tests of this order_id --//
	{
		if (isset($_POST['branch_id'])) {
			$branch_id = $_POST['branch_id'];
			$order_id=$_POST['order_id'];
			$doctor_id=yii::app()->user->id;
			$booked='N';

			//=====Load all test against this branch_id from clinic_lab_tests
			//$clinic_lab_tests = Yii::app()->db->createCommand("SELECT test_id,notes FROM clinic_lab_tests WHERE clinic_id = {$branch_id}")->queryAll();
			$clinic_lab_tests = LabTestsCache::get_clinic_lab_tests($branch_id);
			$lab_tests_array = array();
			if (!empty($clinic_lab_tests)) {
				foreach ($clinic_lab_tests as $lt) {
					$test_id = $lt['test_id'];
					$test_name = $lt['notes'];
					//---Now check this 'test_id' in 'notex_orders_detail' against ' order_id'---//
					$test_check=Yii::app()->db->createCommand("Select id FROM notex_orders_detail WHERE order_id={$order_id} AND subservice_id={$test_id}")->queryScalar();
					if(!empty($test_check))
					{
						$booked='Y';
					}
					else
					{
						$booked='N';
					}

					$lab_tests_array[] = array('test_id'=>$test_id, 'test_name'=>$test_name,'booked'=>$booked);
				}
			}else {
				$lab_tests_array[] = array('test_id' => 0, 'test_name' => 'No Test Found');
			}
			echo json_encode($lab_tests_array);
		}
	}
	public function actionLoadBookedTests()
	{
		if (isset($_POST['branch_id']))
		{
			$branch_id = $_POST['branch_id'];
			$order_id=$_POST['order_id'];
			$doctor_id=yii::app()->user->id;

			//=====Gettting ALL BOOKED TESTS from NotexOrders againt this 'mobile_no' & 'today_dt'====//
			$booked_tests=Yii::app()->db->createCommand("Select id,subservice_id,service_name,checkin_id FROM notex_orders_detail WHERE order_id = {$order_id}")->queryAll();
			if(!empty($booked_tests))
			{
				foreach($booked_tests as $test)
				{
					$test_id=$test['subservice_id'];	$test_name=$test['service_name'];
					$checkin_id=$test['checkin_id'];
					if(!empty($test_id))
					{
						$booked_tests_array[] = array('test_id'=>$test_id, 'test_name'=>$test_name, 'order_detail_id'=>$test['id'], 'checkin_id'=>$checkin_id);
					}
					else
					{
						$booked_tests_array[]=array('test_id'=>0,'test_name'=>'No Test Found');
					}
				}
			}
			else
			{
				$booked_tests_array[]=array('test_id'=>0,'test_name'=>'No Test Found');
			}
			echo json_encode($booked_tests_array);
		}
	}

	//==============Delete From NotexOrdersDetail==============//
	public function actiondelete_from_notex_orders_detail()
	{
		$order_detail_id=$_REQUEST['order_detail_id'];
		NotexOrdersDetail::model()->findByPK($order_detail_id)->delete();
	}
	//=========================================================================//

	//==============LOAD SYSTEM DISORDERS===========//
	public function actionload_disorders()
	{
		if(isset($_REQUEST['system_id']))
		{
			$system_id = $_REQUEST['system_id'];
			$system_disorders = LabTestsCache::get_body_system_disorders($system_id);

			?><select class="form-control select2" name="NotexOrders[body_system_disorder_id]" id="system_disorders"><?php

			if(!empty($system_disorders))
			{
				?><option value='' >--Select Disorder--</option><?php
				foreach($system_disorders as $disorder)
				{
					?><option value="<?php echo $disorder['id']; ?>" ><?php echo $disorder['disorder_heading']; ?></option> <?php
				}
			}
			else
			{
				?><option value="" >--No Data Found--</option><?php
			}
			?> </select> <?php
		}
	}
	//==============================================//

	//============VIEW DISORDER TESTS ==============//
	public function actionViewDisorderTests()
	{
		$this->layout=false;
		$this->render('view_disorder_tests');
	}
	//==============================================//

    /*
     * Load Panels  from ('clinic_orgs')
     */
    public function actionLoadPanels()
    {
        if(Yii::app()->request->getPost('branch_id')) {
            $branch_id = Yii::app()->request->getPost('branch_id');
            $panels = Yii::app()->db->createCommand("Select clinic_orgs.id,org_branch_id,paid_type,Name org_name FROM clinic_orgs JOIN org_branches ON clinic_orgs.org_branch_id = org_branches.id WHERE clinic_branch_id = {$branch_id} AND clinic_orgs.active = 'Y'")->queryAll();
            if(!empty($panels)) {
                $panels_json = json_encode($panels);
            }else{
                $panels_json = json_encode(array(array('id' => 0)));
            }
            echo $panels_json;
        }
    }
    /*
     * Load Packages against 'org_branch_id & clinic_branch_id' from tables ' clinic_org_offers & clinic_packages'
     */
    public function actionLoadPackages()
    {
        if(Yii::app()->request->getPost('branch_id') && Yii::app()->request->getPost('org_branch_id')) {
            $branch_id = Yii::app()->request->getPost('branch_id');
            $org_branch_id = Yii::app()->request->getPost('org_branch_id');
            $system_date = date('Y-m-d');
            $panel_packages = Yii::app()->db->createCommand("SELECT cof.package_id,ofr_type, cp.name FROM clinic_org_offers cof, clinic_packages cp
                                                            WHERE cof.package_id = cp.id and  cof.status = 'Y' AND cof.clinic_branch_id= {$branch_id} AND  cof.expiry_date > '{$system_date}' and cof.org_branch_id = {$org_branch_id} order by cp.name asc
                                                            ")->queryAll();
            if(!empty($panel_packages)) {
                $panel_packages_json = json_encode($panel_packages);
            }else{
                $panel_packages_json = json_encode(array(array('package_id' => 0)));
            }
            echo $panel_packages_json;
        }
    }

	/**
	 * Displays a particular model.
	 * @param integer $id the ID of the model to be displayed
	 */
	public function actionView($id)
	{
		$this->render('view',array(
			'model'=>$this->loadModel($id),
		));
	}

	/**
	 * Creates a new model.
	 * If creation is successful, the browser will be redirected to the 'view' page.
	 */
	public function actionCreate()
	{
		$model=new NotexOrders;

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['NotexOrders']))
		{
			$model->attributes=$_POST['NotexOrders'];
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('create',array(
			'model'=>$model,
		));
	}

	/**
	 * Updates a particular model.
	 * If update is successful, the browser will be redirected to the 'view' page.
	 * @param integer $id the ID of the model to be updated
	 */
	public function actionUpdate($id)
	{
		$model=$this->loadModel($id);

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['NotexOrders']))
		{
			$model->attributes=$_POST['NotexOrders'];
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('update',array(
			'model'=>$model,
		));
	}

	/**
	 * Deletes a particular model.
	 * If deletion is successful, the browser will be redirected to the 'admin' page.
	 * @param integer $id the ID of the model to be deleted
	 */
	public function actionDelete($id)
	{
		$this->loadModel($id)->delete();

		// if AJAX request (triggered by deletion via admin grid view), we should not redirect the browser
		if(!isset($_GET['ajax']))
			$this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('admin'));
	}

	/**
	 * Lists all models.
	 */
	public function actionIndex()
	{
		$dataProvider=new CActiveDataProvider('NotexOrders');
		$this->render('index',array(
			'dataProvider'=>$dataProvider,
		));
	}

	/**
	 * Manages all models.
	 */
	public function actionAdmin()
	{
		$model=new NotexOrders('search');
		$model->unsetAttributes();  // clear any default values
		if(isset($_GET['NotexOrders']))
			$model->attributes=$_GET['NotexOrders'];

		$this->render('admin',array(
			'model'=>$model,
		));
	}

	/**
	 * Returns the data model based on the primary key given in the GET variable.
	 * If the data model is not found, an HTTP exception will be raised.
	 * @param integer $id the ID of the model to be loaded
	 * @return NotexOrders the loaded model
	 * @throws CHttpException
	 */
	public function loadModel($id)
	{
		$model=NotexOrders::model()->findByPk($id);
		if($model===null)
			throw new CHttpException(404,'The requested page does not exist.');
		return $model;
	}

	/**
	 * Performs the AJAX validation.
	 * @param NotexOrders $model the model to be validated
	 */
	protected function performAjaxValidation($model)
	{
		if(isset($_POST['ajax']) && $_POST['ajax']==='notex-orders-form')
		{
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
	}



	public function actionLab_booking2()
	{
		$model = new NotexOrders();

		if(isset($_POST['NotexOrders']))
		{
			$transaction = Yii::app()->db->beginTransaction();
			$model->attributes=$_POST['NotexOrders'];

			$model->entered_on=date('Y-m-d H:i:s');
			$model->entered_by=Yii::app()->user->id;

			//----Search Patient by 'mr_no' and get 'patient_id'----//
			$patient_id = Yii::app()->db->createCommand("SELECT id FROM rm_pr_patients WHERE MR_NO='{$model->reference_no}'")->queryScalar();
			if(!empty($patient_id))
			{
				$model->patient_id = $patient_id;
			}
			//echo '<pre>'; print_r($model); exit();
			if($model->save())
			{

				if(isset($_POST['booked_test_ids']))		//---If any test booked---//
				{
					foreach($_POST['booked_test_ids'] as $index=>$test_id)
					{
						$model_detail=new NotexOrdersDetail();
						$model_detail->order_id=$model->id;
						$model_detail->subservice_id=$test_id;
						$model_detail->service_name=$_POST['booked_test_names'][$index];
						$model_detail->entered_on=date('Y-m-d H:i:s');
						$model_detail->entered_by=Yii::app()->user->id;
						if($model_detail->save())
						{
							Yii::app()->user->setFlash('test_booked','Test Booked');
						}
						else
						{

							Yii::app()->user->setFlash('failed','Failed');
						}

					}
				}
				if($model->home_request==1)
				{
					$row_id=$model->id;
					//==============SEND SMS & EMAIL TO HOME COLLECTION CENTER ALERT========//
					$client_app_events=new ClientAppEvents();
					$client_app_events->send_home_services_alert($row_id,$model->patient_name,$model->mobile_no,$model->for_branch_id);

					//=====================================================================//
				}
				$transaction->commit();
				$model=new NotexOrders();
				Yii::app()->user->setFlash('req_sent','Request Sent');
			}
			else
			{
				$transaction->rollback();
				Yii::app()->user->setFlash('failed','Failed');
			}
		}

		//echo '<pre>'; print_r($model); exit();
		$this->render('lab-booking2',array(
			'model' => $model
		));
	}
}
