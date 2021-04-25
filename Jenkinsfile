node{
        
        stage('GitLab Checkout') {
            //git branch: $branchName, url: 'https://github.com/heniabida/bbn.git'
                checkout scm
                //sh 'echo $branchName'
                //git branch: $branchName, url: 'https://github.com/heniabida/bbn.git'
                
            }  
        
         def buildNum = env.BUILD_NUMBER 
        /*def branchName = "master"*/
        def gitBranch = env.GIT_BRANCH
        def branchName = env.BRANCH_NAME
        def buildTag = env.BUILD_TAG
        def tagName = env.TAG_NAME
        def gitLocalBranch = env.GIT_LOCAL_BRANCH
        def changeId = env.CHANGE_ID
         def changeUrl = env.CHANGE_URL
     def changeTitle = env.CHANGE_TITLE
      
            /* Récupération du commitID long */
            def commitIdLong = sh returnStdout: true, script: 'git rev-parse HEAD'

            /* Récupération du commitID court */
            def commitId = commitIdLong.take(7)

            print """
            ###################################################################################################################################################
            #                                                       BanchName: $branchName                                                                    #
            #                                                       CommitID: $commitId                                                                       #
            #                                                       JobNumber: $buildNum
            #                                                       Build Tag : $buildTag
            #                                                       Tag Name : $tagName
            #                                                       Git Branch : $gitBranch
            #                                                       Git Local Branch : $gitLocalBranch
            #                                                       Change ID : $changeId
            #                                                       Change URL : $changeUrl
            #                                                       Change Title : $changeTitle
            #                                                       
            ###################################################################################################################################################
            """
            

        stage('Install  Dependencies') {      
                
                sh 'composer install'
        }
         stage('Update Packagist') { 
           sh "curl -XPOST -H'content-type:application/json' 'https://packagist.org/api/update-package?username=heniabida&apiToken=BdWa9m27-XtKyRYrgFdi' -d'{"repository":{"url":"https://packagist.org/packages/bbnh/bbnh/"}}'"
            }
}
