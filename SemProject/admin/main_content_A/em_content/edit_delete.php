<!DOCTYPE html>
<html lang="en">


<body>

<tbody id="employeeTableBody">

        <!-- Example Employee -->
        <tr>
            <td>
                <span class="status active">
                    Active
                    </span>
                </td>
                <td>jdelacruz</td>
                <td>Juan Dela Cruz</td>
                <td>juan.delacruz@example.com</td>
    
<!-- Edit/Delete Button -->
      
<td class="text-center">
        <button
        type="button"
        class="btn edit-btn"
        onclick="editEmployee(this)">
            <i class="bi bi-pencil-square"></i>
            Edit
            </button>

        <button
        type="button"
        class="btn delete-btn"
        onclick="deleteEmployee(this)">
            <i class="bi bi-trash"></i>
            Delete
            </button>
</td>
   </tr>
     </tbody>
</body>
</html>