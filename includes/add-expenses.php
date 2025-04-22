<?php
session_start();
error_reporting(0);
include('database.php');

// AI prediction function
function getAIPrediction($description) {
  // API endpoint for AI prediction
  $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
  $api_key = "AIzaSyC29EOW6fpCyotHzh5tOELnVvhfwXw89v0"; // Replace with your actual API key
  
  // Update prompt to enforce JSON format
  $prompt = "Extract expense information from this description: \"$description\". " .
           "Return ONLY a valid JSON object with the following fields if they can be determined: " .
           "category (one of: Food, Transport, Entertainment, Utilities, Shopping, Health, Education, Travel, Other), " .
           "cost (numeric value only), " .
           "date (in YYYY-MM-DD format), " .
           "description (a cleaned up, concise version of the expense). " .
           "Format your response as valid JSON with no additional text, comments, or explanations.";
  
  // Prepare request data for Gemini API
  $data = array(
      'contents' => [
          [
              'parts' => [
                  [
                      'text' => $prompt
                  ]
              ]
          ]
      ],
      'generationConfig' => [
          'temperature' => 0.1,  // Lower temperature for more predictable output
          'topP' => 1,
          'topK' => 32,
          'maxOutputTokens' => 150
      ]
  );

  // Initialize cURL
  $ch = curl_init($api_url . '?key=' . $api_key);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  
  // Execute cURL request
  $response = curl_exec($ch);
  
  // Check for errors
  if(curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    return array('error' => "API request failed: $error");
  }
  
  // Close cURL session
  curl_close($ch);
  
  // Process the response
  $result = json_decode($response, true);
  
  // Debug: Log the full response
  error_log("API Response: " . $response);
  
  // Check if the response is valid
  if(!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    return array('error' => 'Invalid response from AI service');
  }
  
  // Extract the text from Gemini's response
  $text = $result['candidates'][0]['content']['parts'][0]['text'];
  
  // Debug: Log the extracted text
  error_log("Response Text: " . $text);
  
  // Try to extract JSON from the text
  $jsonText = $text;
  
  // Clean up the text - remove code blocks if present
  $jsonText = preg_replace('/```json\s*|\s*```/', '', $jsonText);
  
  // Remove any non-JSON text that might be in the response
  $jsonStart = strpos($jsonText, '{');
  $jsonEnd = strrpos($jsonText, '}');
  
  if($jsonStart !== false && $jsonEnd !== false) {
    $jsonText = substr($jsonText, $jsonStart, $jsonEnd - $jsonStart + 1);
  }
  
  // Debug: Log the JSON text
  error_log("Extracted JSON: " . $jsonText);
  
  // Try to decode the JSON
  $prediction = json_decode($jsonText, true);
  
  if(!$prediction) {
    // Try fallback method for extracting data
    $prediction = array();
    
    // Try to extract category
    if(preg_match('/category[:\s]+"?([^",\n]+)"?/i', $text, $matches)) {
      $prediction['category'] = trim($matches[1]);
    }
    
    // Try to extract cost
    if(preg_match('/cost[:\s]+"?(\d+(?:\.\d+)?)"?/i', $text, $matches)) {
      $prediction['cost'] = trim($matches[1]);
    }
    
    // Try to extract date
    if(preg_match('/date[:\s]+"?(\d{4}-\d{2}-\d{2})"?/i', $text, $matches)) {
      $prediction['date'] = trim($matches[1]);
    }
    
    // Try to extract description
    if(preg_match('/description[:\s]+"([^"]+)"/i', $text, $matches)) {
      $prediction['description'] = trim($matches[1]);
    }
    
    // If we couldn't extract anything, return an error
    if(empty($prediction)) {
      return array('error' => 'Failed to parse prediction data');
    }
  }
  
  // Validate prediction data
  $required_fields = array('category', 'cost', 'date');
  $missing_fields = array();
  
  foreach($required_fields as $field) {
    if(!isset($prediction[$field]) || empty($prediction[$field])) {
      $missing_fields[] = $field;
    }
  }
  
  if(!empty($missing_fields)) {
    $prediction['incomplete'] = true;
    $prediction['missing_fields'] = $missing_fields;
  }
  
  return $prediction;
}

if (strlen($_SESSION['detsuid'] == 0)) {
  header('location:logout.php');
} else {
  if (isset($_POST['submit'])) {
    $userid = $_SESSION['detsuid'];
    $dateexpense = $_POST['dateexpense'];
    $CategoryId = $_POST['CategoryId'];
    $category = $_POST['category'];
    $Description = $_POST['category-description'];
    $costitem = $_POST['costitem'];
    $query = mysqli_query($db, "INSERT INTO tblexpense(UserId, ExpenseDate,CategoryId ,category, ExpenseCost ,Description) SELECT '$userid', '$dateexpense',CategoryId, CategoryName, '$costitem' ,'$Description ' FROM tblcategory WHERE CategoryId = '$category'");
    if ($query) {
      $message = "Expense added successfully";
      echo "<script type='text/javascript'>alert('$message');</script>";
      echo " <script type='text/javascript'>window.location.href = 'manage-expenses.php';</script>";

    } else {
      $message = "Expense could not be added";
      echo "<script type='text/javascript'>alert('$message');</script>";
    }
    
  }
  
  // Handle AI prediction request
  if(isset($_POST['get_ai_prediction'])) {
    $description = $_POST['ai_description'];
    $prediction = getAIPrediction($description);
    
    // Store prediction in session for use in the form
    $_SESSION['ai_prediction'] = $prediction;
    
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($prediction);
    exit;
  }
?>

<!DOCTYPE html>
<!-- Designined by CodingLab | www.youtube.com/codinglabyt -->
<html lang="en" dir="ltr">
  <head>
    <meta charset="UTF-8">
    <!--<title> Responsiive Admin Dashboard | CodingLab </title>-->
    <link rel="stylesheet" href="css/style.css">
    <!-- Boxicons CDN Link -->
    <link href='https://unpkg.com/boxicons@2.0.7/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
     <script src="js/scripts.js"></script>

<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
<script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>

     <style> 
.container {
  background-color: #f2f2f2;
  border-radius: 5px;
  box-shadow: 0px 0px 10px #aaa;
  padding: 20px;
  margin-top: 20px;
}

.form-group label {
  font-weight: bold;
}

.form-control {
  border-radius: 3px;
  border: 1px solid #ccc;
}

.invalid-feedback {
  color: red;
  font-size: 12px;
}

.btn-primary {
  background-color: #007bff;
  border-color: #007bff;
}

.btn-primary:hover {
  background-color: #0069d9;
  border-color: #0062cc;
}

.ai-prediction-box {
  background-color: #e8f4ff;
  border: 1px solid #b8daff;
  border-radius: 5px;
  padding: 15px;
  margin-bottom: 20px;
}

.ai-prediction-box h4 {
  color: #004085;
  margin-bottom: 15px;
}

.ai-suggestion-item {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
}

.ai-suggestion-item .badge {
  font-size: 85%;
}

.ai-error {
  color: #721c24;
  background-color: #f8d7da;
  border: 1px solid #f5c6cb;
  padding: 10px;
  border-radius: 4px;
  margin-top: 10px;
}

     </style>
   </head>
<body>
  <div class="sidebar">
  <div class="logo-details">
      <i class='bx bx-album'></i>
      <span class="logo_name">Expenditure</span>
    </div>
      <ul class="nav-links">
        <li>
          <a href="home.php" >
            <i class='bx bx-grid-alt' ></i>
            <span class="links_name">Dashboard</span>
          </a>
        </li>
        <li>
          <a href="#" class="active">
            <i class='bx bx-box' ></i>
            <span class="links_name">Expenses</span>
          </a>
        </li>
        <li>
          <a href="manage-expenses.php">
            <i class='bx bx-list-ul' ></i>
            <span class="links_name">Manage List</span>
          </a>
        </li>
        <li>
          <a href="lending.php" >
          <i class='bx bx-money'></i>
            <span class="links_name">lending</span>
          </a>
        </li>
        <li>
        <a href="manage-lending.php" >
        <i class='bx bx-coin-stack'></i>
            <span class="links_name">Manage lending</span>
          </a>
        </li>
        <li>
        <a href="analytics.php">
            <i class='bx bx-pie-chart-alt-2' ></i>
            <span class="links_name">Analytics</span>
          </a>
        </li>
        <li>
          <a href="report.php">
          <i class="bx bx-file"></i>
            <span class="links_name">Report</span>
          </a>
        </li>
        <li>
        <a href="user_profile.php">
            <i class='bx bx-cog' ></i>
            <span class="links_name">Setting</span>
          </a>
        </li>
        <li class="log_out">
        <a href="logout.php">
            <i class='bx bx-log-out'></i>
            <span class="links_name">Log out</span>
          </a>
        </li>
      </ul>
  </div>
  <section class="home-section">
    <nav>
      <div class="sidebar-button">
        <i class='bx bx-menu sidebarBtn'></i>
        <span class="dashboard">Expenditure</span>
      </div>
   
      <?php
$uid=$_SESSION['detsuid'];
$ret=mysqli_query($db,"select name  from users where id='$uid'");
$row=mysqli_fetch_array($ret);
$name=$row['name'];

?>

      <div class="profile-details">
  <img src="images/maex.png" alt="">
  <span class="admin_name"><?php echo $name; ?></span>
  <i class='bx bx-chevron-down' id='profile-options-toggle'></i>
  <ul class="profile-options" id='profile-options'>
  <li><a href="user_profile.php"><i class="fas fa-user-circle"></i> User Profile</a></li>
    <!-- <li><a href="#"><i class="fas fa-cog"></i> Account Settings</a></li> -->
    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
  </ul>
</div>
<script>
  const toggleButton = document.getElementById('profile-options-toggle');
  const profileOptions = document.getElementById('profile-options');
  
  toggleButton.addEventListener('click', () => {
    profileOptions.classList.toggle('show');
  });
</script>

    </nav>


      <?php
$uid=$_SESSION['detsuid'];
$ret=mysqli_query($db,"select name  from users where id='$uid'");
$row=mysqli_fetch_array($ret);
$name=$row['name'];

?>

    <div class="home-content">
      <div class="overview-boxes">
     
    <div class="col-md-12">
        <br>
        
        <div class="card">
  <div class="card-header">
    <div class="row">
      <div class="col-md-6">
        <h4 class="card-title">Add Expense</h4>
      </div>
      <div class="col-md-6 text-right">
  <div class="ml-auto">
    <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#add-category-modal">
      <i class="fas fa-plus-circle"></i> Add Category
    </button>
  </div>
</div>

<div class="modal fade" id="add-category-modal" tabindex="-1" role="dialog" aria-labelledby="add-category-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <form id="add-category-form" method="post" action="add_category.php">
        <div class="modal-header">
          <h5 class="modal-title" id="add-category-modal-title">Add Category</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label for="category-name">Category Name</label>
            <input type="text" class="form-control" id="category-name"  name="category-name" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" name="add-category-submit">Add Category</button>
       
        </div>
      </form>
    </div>
  </div>
</div>

    </div>
  </div>
  <div class="card-body">
    <!-- AI Prediction Section -->
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5><i class="fas fa-robot mr-2"></i>AI Expense Prediction</h5>
      </div>
      <div class="card-body">
        <form id="ai-prediction-form">
          <div class="form-group">
            <label for="ai_description">Describe your expense:</label>
            <textarea class="form-control" id="ai_description" name="ai_description" placeholder="Example: Lunch with clients at XYZ Restaurant yesterday, cost $45" rows="3"></textarea>
          </div>
          <button type="button" id="get-prediction-btn" class="btn btn-info">Get AI Suggestion</button>
        </form>
        
        <div id="ai-prediction-results" class="ai-prediction-box mt-3" style="display: none;">
          <h4>AI Suggestions</h4>
          <div id="prediction-content"></div>
          <button type="button" id="apply-prediction-btn" class="btn btn-sm btn-outline-primary mt-2">Apply Suggestions</button>
        </div>
      </div>
    </div>
    
    <form id="expense-form" role="form" method="post" action="" class="needs-validation">
    <div class="form-group">
  <label for="dateexpense">Date of Expense</label>
  <input class="form-control" type="date" id="dateexpense" name="dateexpense" value="<?php echo date('Y-m-d'); ?>" >
</div>


<div class="form-group">
  <label for="category">Category</label>
  <select class="form-control" id="category" name="category" required>
    <option value="" selected disabled>Choose Category</option>
    <?php
    $userid = $_SESSION['detsuid'];
    $query = "SELECT * FROM tblcategory WHERE UserId = $userid";
    $result = mysqli_query($db, $query);
    while ($row = mysqli_fetch_assoc($result)) {
      // Display category options in a dropdown
      echo '<option value="'.$row['CategoryId'].'">'.$row['CategoryName'].'</option>';
    }
    ?>
  </select>
</div>

      <div class="form-group">
        <label for="costitem">Cost of Item</label>
        <input class="form-control" type="number" id="costitem" name="costitem" required>
         </div>

        <div class="form-group">
            <label for="category-description">Description</label>
            <textarea class="form-control" id="category-description" name="category-description" required></textarea>
          </div>

    
      <div class="form-group">
        <button type="submit" class="btn btn-primary" name="submit">Add</button>
      </div>
    </form>
    <div id="success-message" class="alert alert-success" style="display:none;">
      Expense added successfully.
    </div>
  </div>
</div>

      </div>
    </div>
    
  </section>
  
<script>
   let sidebar = document.querySelector(".sidebar");
let sidebarBtn = document.querySelector(".sidebarBtn");
sidebarBtn.onclick = function() {
  sidebar.classList.toggle("active");
  if(sidebar.classList.contains("active")){
  sidebarBtn.classList.replace("bx-menu" ,"bx-menu-alt-right");
}else
  sidebarBtn.classList.replace("bx-menu-alt-right", "bx-menu");
}

// AI Prediction Feature
$(document).ready(function() {
  // Replace with a non-slim version of jQuery for AJAX support
  if ($.fn.jquery.indexOf('slim') > -1) {
    console.warn('Using slim jQuery version. AJAX features may not work.');
  }

  let currentPrediction = null;

  $('#get-prediction-btn').click(function() {
    const description = $('#ai_description').val().trim();
    console.log(description);
    
    if (!description) {
      alert('Please provide a description of your expense');
      return;
    }
    
    // Show loading indicator
    $('#prediction-content').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Getting AI suggestions...</div>');
    $('#ai-prediction-results').show();
    
    // Use $.ajax instead of $.post to handle more options
    $.ajax({
      url: 'add-expenses.php',
      type: 'POST',
      dataType: 'json',
      data: {
        get_ai_prediction: 1,
        ai_description: description
      },
      success: function(response) {
        if (response.error) {
          $('#prediction-content').html(
            '<div class="ai-error">' +
            '<i class="fas fa-exclamation-circle"></i> ' +
            'Error getting prediction: ' + response.error +
            '</div>'
          );
          return;
        }
        
        currentPrediction = response;
        
        let html = '<div class="list-group">';
        
        if (response.category) {
          html += '<div class="ai-suggestion-item">' +
                 '<span><strong>Category:</strong> ' + response.category + '</span>' +
                 '<span class="badge badge-primary">Suggested</span>' +
                 '</div>';
        }
        
        if (response.cost) {
          html += '<div class="ai-suggestion-item">' +
                 '<span><strong>Cost:</strong> $' + response.cost + '</span>' +
                 '<span class="badge badge-primary">Suggested</span>' +
                 '</div>';
        }
        
        if (response.date) {
          html += '<div class="ai-suggestion-item">' +
                 '<span><strong>Date:</strong> ' + response.date + '</span>' +
                 '<span class="badge badge-primary">Suggested</span>' +
                 '</div>';
        }
        
        html += '</div>';
        
        if (response.incomplete && response.missing_fields) {
          html += '<div class="ai-error mt-3">' +
                 '<i class="fas fa-exclamation-triangle"></i> ' +
                 '<strong>Missing information:</strong> ' + response.missing_fields.join(', ') +
                 '</div>';
        }
        
        $('#prediction-content').html(html);
      },
      error: function(xhr, status, error) {
        $('#prediction-content').html(
          '<div class="ai-error">' +
          '<i class="fas fa-exclamation-circle"></i> ' +
          'Error connecting to AI service. Please try again later.' +
          '</div>'
        );
        console.error('AJAX error:', status, error);
      }
    });
  });
  
  $('#apply-prediction-btn').click(function() {
    if (!currentPrediction) return;
    
    // Apply the AI suggestions to the form
    if (currentPrediction.category) {
      // Find the category ID by name
      const categorySelect = $('#category');
      categorySelect.find('option').each(function() {
        if ($(this).text().toLowerCase() === currentPrediction.category.toLowerCase()) {
          categorySelect.val($(this).val());
          return false;
        }
      });
    }
    
    if (currentPrediction.cost) {
      $('#costitem').val(currentPrediction.cost);
    }
    
    if (currentPrediction.date) {
      $('#dateexpense').val(currentPrediction.date);
    }
    
    // Copy the description to the form
    const description = $('#ai_description').val();
    $('#category-description').val(description);
    
    // Scroll to the form
    $('html, body').animate({
      scrollTop: $("#expense-form").offset().top - 100
    }, 500);
  });
});
</script>

 <?php }?>

 <!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.0/css/bootstrap.min.css">

<!-- Bootstrap JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.9.3/umd/popper.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.0/js/bootstrap.min.js"></script>

<!-- Bootstrap Validation Plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.19.3/jquery.validate.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.19.3/additional-methods.min.js"></script>