        def buildNum = env.BUILD_NUMBER 
        def branchName = "master"
        /*def gitBranch = env.GIT_BRANCH
        def buildTag = env.BUILD_TAG
        def tagName = env.TAG_NAME
      
            /* Récupération du commitID long */
            //def commitIdLong = sh returnStdout: true, script: 'git rev-parse HEAD'

            /* Récupération du commitID court */
            //def commitId = commitIdLong.take(7)

           /* print """
            ###################################################################################################################################################
            #                                                       BanchName: $branchName                                                                    #
            #                                                       CommitID: $commitId                                                                       #
            #                                                       JobNumber: $buildNum
            #                                                       Build Tag : $buildTag
            #                                                       Tag Name : $tagName
            #                                                       Git Branch : $gitBranch
            #                                                       
            ###################################################################################################################################################
            """*/
            stage('GitLab Checkout') {
            
                checkout scm
                sh 'echo $BRANCH_NAME'
                //git branch: $branchName, url: 'https://github.com/heniabida/bbn.git'
                
            }            
        stage('Install  Dependencies') {      
                
                sh 'composer install'
  
            }
    }
