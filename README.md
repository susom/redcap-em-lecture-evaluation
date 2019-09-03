# Lecture Evaluations

This EM will allow students to anonymously evaluate lectures. 

### EM configuration

#### From Configurations
Create following forms:
1. Lecture: id(Primary Record ID), Date, Instructor, Topic, Course
2. Student: First Name, Last Name, Email, **Hash**, **Student URL**(Please note that Hash and Student URL are required so each student)
3. Lecture Student Map: this form will define the relationship between lectures and students based on Year/Semester/Class using checkboxes.
4. Evaluation Setup: Evaluation Lecture Id(**evaluation_lecture_id** required), Evaluation Student Id (**evaluation_student_id** required), Evaluation Date (**evaluation_date** required). 
5. Evaluation Survey: you can define your survey questions here. 


#### Arms Configurations
Create Following Arms:
1. Lectures with Lecture Event Name.
2. Students with Student Event Name.
3. Evaluations with Evaluation Event Name.

#### Instruments/Events designation
Please make sure to assign each instrument to corresponding arm based on following: 
* Lectures Arm Contains Following Instruments:
1. Lecture
2. Lecture Student Map
* Student Arm Contains Following Instruments:
1. Student 
2. Lecture Student Map
* Evaluation Arm Contains Following Instruments:
1. Lecture
2. Evaluation Setup 
3. Evaluation Survey. 

## Create Lectures/Students Records 
1. Create your map checkboxes 
2. Create/Import your lectures records.
3. For each Lecture check appropriate checkbox in Lecture Student Map. 
4. Create/Import Students records(No need to create hash and URL the EM will generate them for each student). 
5. For each Student check appropriate checkbox in Lecture Student Map. 


## View Students
You can see All student records with stats of how many evaluation submitted/pending/incomplete from "View Student Page".